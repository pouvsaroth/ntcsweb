<?php

declare(strict_types=1);

namespace App\Services\Assets;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetTransfer;
use App\Models\User;
use App\Support\Assets\AssetHistoryEvent;
use App\Support\Assets\AssetStatus;
use App\Support\Assets\AssignableType;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Day-to-day asset operations: create/update, assign/return, transfer,
 * and the single choke point (`changeStatus()`) every other Asset service
 * (Issue/Repair/Maintenance/Lifecycle) must call to move an asset's status —
 * so `AssetStatusTransitionService::assertCanTransition()` and the paired
 * AssetHistory + audit log entries fire uniformly no matter which module
 * triggers the change. Retire/dispose/lost/found live in
 * AssetLifecycleService instead — see that class for why they're kept
 * separate.
 */
final class AssetService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AssetNumberGenerator $numbers,
        private readonly AssetStatusTransitionService $transitions,
        private readonly AssetHistoryRecorder $history,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Asset
    {
        return DB::transaction(function () use ($data, $actor) {
            $tenant = $this->context->getOrFail();

            $asset = Asset::query()->create([
                ...$data,
                'asset_number' => $this->numbers->next($tenant),
                'status' => $data['status'] ?? AssetStatus::IN_STOCK,
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->log(
                AuditAction::ASSET_CREATED,
                'Assets',
                $asset,
                new: ['name' => $asset->name, 'category_id' => $asset->category_id],
                description: "Created asset {$asset->asset_number} — {$asset->name}",
                actor: $actor,
            );

            $this->history->log($asset, AssetHistoryEvent::CREATED, "Asset {$asset->asset_number} created.", actor: $actor);

            return $asset;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Asset $asset, array $data, User $actor): Asset
    {
        return DB::transaction(function () use ($asset, $data, $actor) {
            /** @var Asset $asset */
            $asset = Asset::query()->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();

            unset($data['status'], $data['condition'], $data['location_id'], $data['department_id'], $data['asset_number']);

            $old = $asset->only(array_keys($data));
            $asset->update($data);

            $this->audit->log(
                AuditAction::ASSET_UPDATED,
                'Assets',
                $asset,
                old: $old,
                new: $asset->only(array_keys($data)),
                description: "Updated asset {$asset->asset_number}",
                actor: $actor,
            );

            $this->history->log($asset, AssetHistoryEvent::UPDATED, "Asset {$asset->asset_number} details updated.", $old, $asset->only(array_keys($data)), $actor);

            return $asset;
        });
    }

    /**
     * Closes any currently open assignment and opens a new one — never
     * updates a row in place, so the full custody history survives.
     *
     * @param  array{assigned_date?:string, expected_return_date?:string|null, condition_at_assignment?:string|null, notes?:string|null}  $data
     */
    public function assign(Asset $asset, Model $assignable, User $actor, array $data = []): AssetAssignment
    {
        if (! AssignableType::isAllowed($assignable::class)) {
            throw ValidationException::withMessages(['assignable_type' => 'This type of record cannot be assigned an asset.']);
        }

        return DB::transaction(function () use ($asset, $assignable, $actor, $data) {
            /** @var Asset $asset */
            $asset = Asset::query()->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();

            if ($asset->isClosed()) {
                throw ValidationException::withMessages(['status' => 'A retired or disposed asset cannot be assigned.']);
            }

            $open = AssetAssignment::query()->where('asset_id', $asset->getKey())->active()->first();
            if ($open) {
                $open->update(['status' => AssetAssignment::RETURNED, 'returned_date' => now()]);
            }

            $assignment = AssetAssignment::query()->create([
                'asset_id' => $asset->getKey(),
                'assignable_type' => $assignable->getMorphClass(),
                'assignable_id' => $assignable->getKey(),
                'assigned_by' => $actor->getKey(),
                'assigned_date' => $data['assigned_date'] ?? now()->toDateString(),
                'expected_return_date' => $data['expected_return_date'] ?? null,
                'condition_at_assignment' => $data['condition_at_assignment'] ?? $asset->condition,
                'notes' => $data['notes'] ?? null,
            ]);

            $target = method_exists($assignable, 'auditDisplayName') ? $assignable->auditDisplayName() : '#'.$assignable->getKey();

            $this->changeStatus($asset, AssetStatus::ASSIGNED, $actor, "Assigned to {$target}");

            $this->audit->log(
                AuditAction::ASSET_ASSIGNED,
                'Assets',
                $asset,
                new: ['assignable_type' => $assignable->getMorphClass(), 'assignable_id' => $assignable->getKey()],
                description: "Assigned asset {$asset->asset_number} to {$target}",
                actor: $actor,
            );

            $this->history->log($asset, AssetHistoryEvent::ASSIGNED, "Assigned to {$target}.", actor: $actor);

            return $assignment;
        });
    }

    /**
     * @param  array{condition_at_return?:string|null, notes?:string|null}  $data
     */
    public function returnAsset(Asset $asset, User $actor, array $data = []): Asset
    {
        return DB::transaction(function () use ($asset, $actor, $data) {
            /** @var Asset $asset */
            $asset = Asset::query()->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();

            $open = AssetAssignment::query()->where('asset_id', $asset->getKey())->active()->first();
            if (! $open) {
                throw ValidationException::withMessages(['status' => 'This asset is not currently assigned.']);
            }

            $open->update([
                'status' => AssetAssignment::RETURNED,
                'returned_date' => now(),
                'condition_at_return' => $data['condition_at_return'] ?? null,
                'notes' => $data['notes'] ?? $open->notes,
            ]);

            if (! empty($data['condition_at_return'])) {
                $asset->update(['condition' => $data['condition_at_return']]);
            }

            $this->changeStatus($asset, AssetStatus::IN_STOCK, $actor, 'Returned to stock');

            $this->audit->log(
                AuditAction::ASSET_RETURNED,
                'Assets',
                $asset,
                description: "Returned asset {$asset->asset_number} to stock",
                actor: $actor,
            );

            $this->history->log($asset, AssetHistoryEvent::RETURNED, 'Returned to stock.', actor: $actor);

            return $asset->refresh();
        });
    }

    /**
     * @param  array{to_location_id?:int|null, to_department_id?:int|null, reason?:string|null, notes?:string|null}  $data
     */
    public function transfer(Asset $asset, array $data, User $actor): AssetTransfer
    {
        return DB::transaction(function () use ($asset, $data, $actor) {
            /** @var Asset $asset */
            $asset = Asset::query()->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();

            $fromLocationId = $asset->location_id;
            $fromDepartmentId = $asset->department_id;
            $toLocationId = array_key_exists('to_location_id', $data) ? $data['to_location_id'] : $fromLocationId;
            $toDepartmentId = array_key_exists('to_department_id', $data) ? $data['to_department_id'] : $fromDepartmentId;

            $transfer = AssetTransfer::query()->create([
                'asset_id' => $asset->getKey(),
                'from_location_id' => $fromLocationId,
                'to_location_id' => $toLocationId,
                'from_department_id' => $fromDepartmentId,
                'to_department_id' => $toDepartmentId,
                'transferred_by' => $actor->getKey(),
                'transfer_date' => now()->toDateString(),
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $asset->update(['location_id' => $toLocationId, 'department_id' => $toDepartmentId]);

            $this->audit->log(
                AuditAction::ASSET_TRANSFERRED,
                'Assets',
                $asset,
                old: ['location_id' => $fromLocationId, 'department_id' => $fromDepartmentId],
                new: ['location_id' => $toLocationId, 'department_id' => $toDepartmentId],
                description: "Transferred asset {$asset->asset_number}",
                actor: $actor,
            );

            $this->history->log(
                $asset,
                AssetHistoryEvent::TRANSFERRED,
                "Transferred asset {$asset->asset_number}.".($data['reason'] ?? '' ? ' Reason: '.$data['reason'] : ''),
                ['location_id' => $fromLocationId, 'department_id' => $fromDepartmentId],
                ['location_id' => $toLocationId, 'department_id' => $toDepartmentId],
                $actor,
            );

            return $transfer;
        });
    }

    public function changeCondition(Asset $asset, string $condition, User $actor, ?string $notes = null): Asset
    {
        return DB::transaction(function () use ($asset, $condition, $actor, $notes) {
            /** @var Asset $asset */
            $asset = Asset::query()->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();
            $old = $asset->condition;

            if ($old === $condition) {
                return $asset;
            }

            $asset->update(['condition' => $condition]);

            $this->audit->log(
                AuditAction::ASSET_CONDITION_CHANGED,
                'Assets',
                $asset,
                old: ['condition' => $old],
                new: ['condition' => $condition],
                description: "Asset {$asset->asset_number} condition changed from {$old} to {$condition}",
                actor: $actor,
            );

            $this->history->log(
                $asset,
                AssetHistoryEvent::CONDITION_CHANGED,
                "Condition changed from {$old} to {$condition}.".($notes ? " {$notes}" : ''),
                ['condition' => $old],
                ['condition' => $condition],
                $actor,
            );

            return $asset;
        });
    }

    /**
     * The single choke point for status changes — every other Asset service
     * calls this instead of writing to `assets.status` directly, so the
     * transition guard and the paired audit/history entries can never be
     * skipped. Assumes it is called from within an existing DB transaction
     * that already holds a row lock on $asset (see callers).
     */
    public function changeStatus(Asset $asset, string $newStatus, User $actor, ?string $reason = null): Asset
    {
        $old = $asset->status;
        $this->transitions->assertCanTransition($old, $newStatus);

        if ($old === $newStatus) {
            return $asset;
        }

        $asset->update(['status' => $newStatus]);

        $this->audit->log(
            AuditAction::ASSET_STATUS_CHANGED,
            'Assets',
            $asset,
            old: ['status' => $old],
            new: ['status' => $newStatus],
            description: "Asset {$asset->asset_number} status changed from {$old} to {$newStatus}".($reason ? " ({$reason})" : ''),
            actor: $actor,
        );

        $this->history->log(
            $asset,
            AssetHistoryEvent::STATUS_CHANGED,
            "Status changed from {$old} to {$newStatus}.".($reason ? " {$reason}" : ''),
            ['status' => $old],
            ['status' => $newStatus],
            $actor,
        );

        return $asset;
    }
}
