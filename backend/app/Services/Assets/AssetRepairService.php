<?php

declare(strict_types=1);

namespace App\Services\Assets;

use App\Models\Asset;
use App\Models\AssetIssue;
use App\Models\AssetRepair;
use App\Models\Expense;
use App\Models\User;
use App\Services\Accounting\AccountingNumberGenerator;
use App\Support\Accounting\ExpenseStatus;
use App\Support\Assets\AssetHistoryEvent;
use App\Support\Assets\AssetStatus;
use App\Support\Assets\RepairStatus;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sending an asset out for repair, tracking cost, and completing the job.
 * `total_cost` is always recomputed server-side from the five cost
 * components — never trusted from the request (spec: repair totals must
 * never be trusted from the frontend). completeRepair() creates the linked
 * Expense in PENDING_APPROVAL, following the existing approval-gated
 * Expense workflow rather than posting straight to the ledger.
 */
final class AssetRepairService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AssetNumberGenerator $numbers,
        private readonly AccountingNumberGenerator $accountingNumbers,
        private readonly AssetService $assets,
        private readonly AssetStatusTransitionService $transitions,
        private readonly AssetHistoryRecorder $history,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{repair_shop_id?:int|null, sent_date?:string, expected_return_date?:string|null, problem_description?:string|null}  $data
     */
    public function sendToRepair(Asset $asset, array $data, User $actor, ?AssetIssue $issue = null): AssetRepair
    {
        return DB::transaction(function () use ($asset, $data, $actor, $issue) {
            $tenant = $this->context->getOrFail();
            /** @var Asset $asset */
            $asset = Asset::query()->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();

            $repair = AssetRepair::query()->create([
                'repair_number' => $this->numbers->nextRepairNumber($tenant),
                'asset_id' => $asset->getKey(),
                'issue_id' => $issue?->getKey(),
                'repair_shop_id' => $data['repair_shop_id'] ?? null,
                'sent_date' => $data['sent_date'] ?? now()->toDateString(),
                'expected_return_date' => $data['expected_return_date'] ?? null,
                'problem_description' => $data['problem_description'] ?? $issue?->description,
                'status' => RepairStatus::SENT_TO_SHOP,
                'created_by' => $actor->getKey(),
            ]);

            $this->assets->changeStatus($asset, AssetStatus::UNDER_REPAIR, $actor, "Sent to repair ({$repair->repair_number})");

            $this->audit->log(
                AuditAction::ASSET_SENT_TO_REPAIR,
                'Assets',
                $asset,
                new: ['repair_number' => $repair->repair_number, 'repair_shop_id' => $repair->repair_shop_id],
                description: "Sent asset {$asset->asset_number} to repair ({$repair->repair_number})",
                actor: $actor,
            );

            $this->history->log($asset, AssetHistoryEvent::SENT_TO_REPAIR, "Sent to repair — {$repair->repair_number}.", actor: $actor);

            return $repair;
        });
    }

    /**
     * Records diagnosis/progress and recomputes total_cost if any cost field
     * is present — never trusts a posted `total_cost`.
     *
     * @param  array{status?:string, diagnosis?:string|null, repair_description?:string|null, diagnosis_cost?:float, parts_cost?:float, labor_cost?:float, transport_cost?:float, other_cost?:float}  $data
     */
    public function recordProgress(AssetRepair $repair, array $data, User $actor): AssetRepair
    {
        return DB::transaction(function () use ($repair, $data, $actor) {
            /** @var AssetRepair $repair */
            $repair = AssetRepair::query()->whereKey($repair->getKey())->lockForUpdate()->firstOrFail();

            if ($repair->isClosed()) {
                throw ValidationException::withMessages(['status' => 'This repair is already returned or cancelled.']);
            }

            $fields = array_intersect_key($data, array_flip([
                'status', 'diagnosis', 'repair_description',
                'diagnosis_cost', 'parts_cost', 'labor_cost', 'transport_cost', 'other_cost',
            ]));

            $repair->fill($fields);
            $repair->total_cost = $this->computeTotal($repair);
            $repair->save();

            $this->audit->log(
                AuditAction::ASSET_REPAIR_STARTED,
                'Assets',
                $repair,
                new: $fields,
                description: "Updated repair {$repair->repair_number}",
                actor: $actor,
            );

            return $repair;
        });
    }

    /**
     * @param  array{repair_description?:string|null, condition_after_repair?:string|null, warranty_days?:int|null, diagnosis_cost?:float, parts_cost?:float, labor_cost?:float, transport_cost?:float, other_cost?:float}  $data
     */
    public function complete(AssetRepair $repair, array $data, int $expenseAccountId, User $actor): AssetRepair
    {
        return DB::transaction(function () use ($repair, $data, $expenseAccountId, $actor) {
            /** @var AssetRepair $repair */
            $repair = AssetRepair::query()->whereKey($repair->getKey())->lockForUpdate()->firstOrFail();

            if ($repair->isClosed()) {
                throw ValidationException::withMessages(['status' => 'This repair is already returned or cancelled.']);
            }

            $fields = array_intersect_key($data, array_flip([
                'repair_description', 'condition_after_repair', 'warranty_days',
                'diagnosis_cost', 'parts_cost', 'labor_cost', 'transport_cost', 'other_cost',
            ]));

            $repair->fill($fields);
            $repair->total_cost = $this->computeTotal($repair);
            $repair->status = RepairStatus::REPAIR_COMPLETED;
            $repair->actual_return_date = now()->toDateString();

            $tenant = $this->context->getOrFail();
            $asset = $repair->asset()->lockForUpdate()->firstOrFail();

            $expense = Expense::query()->create([
                'expense_number' => $this->accountingNumbers->nextExpenseNumber($tenant),
                'expense_date' => now()->toDateString(),
                'account_id' => $expenseAccountId,
                'amount' => (float) $repair->total_cost,
                'vendor' => $repair->repairShop?->name,
                'description' => "Repair {$repair->repair_number} for asset {$asset->asset_number}",
                'reference_number' => $repair->repair_number,
                'status' => ExpenseStatus::PENDING_APPROVAL,
                'created_by' => $actor->getKey(),
                'reference_type' => AssetRepair::class,
                'reference_id' => $repair->getKey(),
            ]);

            $repair->expense_id = $expense->getKey();
            $repair->save();

            if (! empty($fields['condition_after_repair'])) {
                $asset->update(['condition' => $fields['condition_after_repair']]);
            }

            // UNDER_REPAIR can only move to REPAIR_COMPLETED directly (see
            // AssetStatus::TRANSITIONS) — READY_FOR_USE is one more explicit
            // step (via AssetService::changeStatus or the next assign/return
            // action), matching the spec's own UNDER_REPAIR -> REPAIR_COMPLETED
            // -> READY_FOR_USE diagram rather than skipping a state.
            $this->assets->changeStatus($asset, AssetStatus::REPAIR_COMPLETED, $actor, "Repair completed ({$repair->repair_number})");

            $this->audit->log(
                AuditAction::ASSET_REPAIR_COMPLETED,
                'Assets',
                $repair,
                new: ['total_cost' => (float) $repair->total_cost, 'expense_id' => $expense->getKey()],
                description: "Completed repair {$repair->repair_number} — total cost \${$repair->total_cost}",
                actor: $actor,
            );

            $this->history->log($asset, AssetHistoryEvent::REPAIR_COMPLETED, "Repair {$repair->repair_number} completed — total cost \${$repair->total_cost}.", actor: $actor);

            return $repair;
        });
    }

    public function decide(AssetRepair $repair, string $decision, string $reason, User $actor): AssetRepair
    {
        return DB::transaction(function () use ($repair, $decision, $reason, $actor) {
            /** @var AssetRepair $repair */
            $repair = AssetRepair::query()->whereKey($repair->getKey())->lockForUpdate()->firstOrFail();

            $repair->update([
                'decision' => $decision,
                'decision_by' => $actor->getKey(),
                'decision_date' => now()->toDateString(),
                'decision_reason' => $reason,
            ]);

            $this->audit->log(
                AuditAction::ASSET_REPAIR_STARTED,
                'Assets',
                $repair,
                new: ['decision' => $decision, 'reason' => $reason],
                description: "Recorded decision for repair {$repair->repair_number}: {$decision} — {$reason}",
                actor: $actor,
            );

            return $repair;
        });
    }

    public function cancel(AssetRepair $repair, string $reason, User $actor): AssetRepair
    {
        return DB::transaction(function () use ($repair, $reason, $actor) {
            /** @var AssetRepair $repair */
            $repair = AssetRepair::query()->whereKey($repair->getKey())->lockForUpdate()->firstOrFail();

            if ($repair->isClosed()) {
                throw ValidationException::withMessages(['status' => 'This repair is already returned or cancelled.']);
            }

            $repair->update(['status' => RepairStatus::CANCELLED]);

            $asset = $repair->asset()->lockForUpdate()->firstOrFail();
            if ($this->transitions->canTransition($asset->status, AssetStatus::BROKEN)) {
                $this->assets->changeStatus($asset, AssetStatus::BROKEN, $actor, "Repair {$repair->repair_number} cancelled: {$reason}");
            }

            $this->audit->log(
                AuditAction::ASSET_REPAIR_CANCELLED,
                'Assets',
                $repair,
                new: ['reason' => $reason],
                description: "Cancelled repair {$repair->repair_number}: {$reason}",
                actor: $actor,
            );

            return $repair;
        });
    }

    private function computeTotal(AssetRepair $repair): float
    {
        return round(
            (float) $repair->diagnosis_cost + (float) $repair->parts_cost + (float) $repair->labor_cost
            + (float) $repair->transport_cost + (float) $repair->other_cost,
            2
        );
    }
}
