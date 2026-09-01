<?php

declare(strict_types=1);

namespace App\Services\Assets;

use App\Models\Asset;
use App\Models\AssetMaintenance;
use App\Models\User;
use App\Support\Assets\AssetHistoryEvent;
use App\Support\Assets\MaintenanceStatus;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Scheduling and completing preventive maintenance. No background job
 * generates recurring tasks — `next_maintenance_date` is computed once, at
 * completion time, from `recurrence_interval_months`, and read passively by
 * the dashboard's "upcoming maintenance" panel.
 */
final class AssetMaintenanceService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AssetNumberGenerator $numbers,
        private readonly AssetHistoryRecorder $history,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{maintenance_type:string, scheduled_date:string, description?:string|null, repair_shop_id?:int|null, recurrence_interval_months?:int|null, notes?:string|null}  $data
     */
    public function schedule(Asset $asset, array $data, User $actor): AssetMaintenance
    {
        return DB::transaction(function () use ($asset, $data, $actor) {
            $tenant = $this->context->getOrFail();

            $maintenance = AssetMaintenance::query()->create([
                'maintenance_number' => $this->numbers->nextMaintenanceNumber($tenant),
                'asset_id' => $asset->getKey(),
                'maintenance_type' => $data['maintenance_type'],
                'scheduled_date' => $data['scheduled_date'],
                'description' => $data['description'] ?? null,
                'repair_shop_id' => $data['repair_shop_id'] ?? null,
                'recurrence_interval_months' => $data['recurrence_interval_months'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->log(
                AuditAction::ASSET_MAINTENANCE_SCHEDULED,
                'Assets',
                $maintenance,
                new: ['scheduled_date' => (string) $maintenance->scheduled_date, 'maintenance_type' => $maintenance->maintenance_type],
                description: "Scheduled maintenance {$maintenance->maintenance_number} for asset {$asset->asset_number}",
                actor: $actor,
            );

            $this->history->log($asset, AssetHistoryEvent::MAINTENANCE_SCHEDULED, "Maintenance scheduled: {$maintenance->maintenance_type} on {$maintenance->scheduled_date}.", actor: $actor);

            return $maintenance;
        });
    }

    /**
     * @param  array{completed_date?:string, cost?:float|null, description?:string|null}  $data
     */
    public function complete(AssetMaintenance $maintenance, array $data, User $actor): AssetMaintenance
    {
        return DB::transaction(function () use ($maintenance, $data, $actor) {
            /** @var AssetMaintenance $maintenance */
            $maintenance = AssetMaintenance::query()->whereKey($maintenance->getKey())->lockForUpdate()->firstOrFail();

            if ($maintenance->status === MaintenanceStatus::COMPLETED || $maintenance->status === MaintenanceStatus::CANCELLED) {
                throw ValidationException::withMessages(['status' => 'This maintenance is already completed or cancelled.']);
            }

            $completedDate = $data['completed_date'] ?? now()->toDateString();

            $nextDate = $maintenance->recurrence_interval_months
                ? Carbon::parse($completedDate)->addMonths($maintenance->recurrence_interval_months)->toDateString()
                : null;

            $maintenance->update([
                'status' => MaintenanceStatus::COMPLETED,
                'completed_date' => $completedDate,
                'cost' => $data['cost'] ?? $maintenance->cost,
                'description' => $data['description'] ?? $maintenance->description,
                'next_maintenance_date' => $nextDate,
            ]);

            $asset = $maintenance->asset;

            $this->audit->log(
                AuditAction::ASSET_MAINTENANCE_COMPLETED,
                'Assets',
                $maintenance,
                new: ['completed_date' => $completedDate, 'cost' => (float) ($data['cost'] ?? 0)],
                description: "Completed maintenance {$maintenance->maintenance_number} for asset {$asset->asset_number}",
                actor: $actor,
            );

            $this->history->log($asset, AssetHistoryEvent::MAINTENANCE_COMPLETED, "Maintenance completed: {$maintenance->maintenance_type}.".($nextDate ? " Next due {$nextDate}." : ''), actor: $actor);

            return $maintenance;
        });
    }

    public function cancel(AssetMaintenance $maintenance, User $actor): AssetMaintenance
    {
        return DB::transaction(function () use ($maintenance, $actor) {
            /** @var AssetMaintenance $maintenance */
            $maintenance = AssetMaintenance::query()->whereKey($maintenance->getKey())->lockForUpdate()->firstOrFail();

            $maintenance->update(['status' => MaintenanceStatus::CANCELLED]);

            $this->audit->log(
                AuditAction::ASSET_MAINTENANCE_COMPLETED,
                'Assets',
                $maintenance,
                description: "Cancelled maintenance {$maintenance->maintenance_number}",
                actor: $actor,
            );

            return $maintenance;
        });
    }
}
