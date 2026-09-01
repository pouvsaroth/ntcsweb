<?php

declare(strict_types=1);

namespace App\Services\Assets;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\User;
use App\Support\Assets\AssetHistoryEvent;
use App\Support\Assets\AssetStatus;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The lifecycle-ending / legal-weight transitions — retire, dispose, mark
 * lost/found — kept separate from AssetService's day-to-day operations the
 * same way ExpenseService::cancel() is a distinct, more guarded action than
 * record(). All status changes still go through AssetService::changeStatus()
 * so the transition guard can never be bypassed even here.
 */
final class AssetLifecycleService
{
    public function __construct(
        private readonly AssetService $assets,
        private readonly AssetHistoryRecorder $history,
        private readonly AuditLogger $audit,
    ) {}

    public function retire(Asset $asset, string $reason, User $actor): Asset
    {
        return DB::transaction(function () use ($asset, $reason, $actor) {
            /** @var Asset $asset */
            $asset = Asset::query()->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();

            $this->closeOpenAssignment($asset);
            $this->assets->changeStatus($asset, AssetStatus::RETIRED, $actor, $reason);

            $this->audit->log(
                AuditAction::ASSET_RETIRED,
                'Assets',
                $asset,
                new: ['reason' => $reason],
                description: "Retired asset {$asset->asset_number}: {$reason}",
                actor: $actor,
            );

            $this->history->log($asset, AssetHistoryEvent::RETIRED, "Retired: {$reason}.", actor: $actor);

            return $asset->refresh();
        });
    }

    /**
     * @param  array{method:string, value?:float|null, reason:string, approved_by?:int|null, notes?:string|null}  $data
     */
    public function dispose(Asset $asset, array $data, User $actor): Asset
    {
        return DB::transaction(function () use ($asset, $data, $actor) {
            /** @var Asset $asset */
            $asset = Asset::query()->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();

            $this->closeOpenAssignment($asset, $actor);
            $this->assets->changeStatus($asset, AssetStatus::DISPOSED, $actor, $data['reason']);

            $asset->update([
                'disposal_date' => now()->toDateString(),
                'disposal_reason' => $data['reason'],
                'disposal_method' => $data['method'],
                'disposal_value' => $data['value'] ?? null,
                'disposal_approved_by' => $data['approved_by'] ?? $actor->getKey(),
                'disposed_by' => $actor->getKey(),
            ]);

            $this->audit->log(
                AuditAction::ASSET_DISPOSED,
                'Assets',
                $asset,
                new: ['method' => $data['method'], 'reason' => $data['reason'], 'value' => $data['value'] ?? null],
                description: "Disposed asset {$asset->asset_number} ({$data['method']}): {$data['reason']}",
                actor: $actor,
            );

            $this->history->log($asset, AssetHistoryEvent::DISPOSED, "Disposed via {$data['method']}: {$data['reason']}.", actor: $actor);

            return $asset->refresh();
        });
    }

    /**
     * @param  array{last_known_location?:string|null, description?:string|null}  $data
     */
    public function markLost(Asset $asset, array $data, User $actor): Asset
    {
        return DB::transaction(function () use ($asset, $data, $actor) {
            /** @var Asset $asset */
            $asset = Asset::query()->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();

            $this->assets->changeStatus($asset, AssetStatus::LOST, $actor, $data['description'] ?? null);

            $note = trim(($data['description'] ?? '').(isset($data['last_known_location']) ? " Last known location: {$data['last_known_location']}." : ''));

            $this->audit->log(
                AuditAction::ASSET_LOST,
                'Assets',
                $asset,
                new: $data,
                description: "Reported asset {$asset->asset_number} as lost".($note ? " — {$note}" : ''),
                actor: $actor,
            );

            $this->history->log($asset, AssetHistoryEvent::LOST, $note !== '' ? $note : 'Reported lost.', actor: $actor);

            return $asset->refresh();
        });
    }

    /** LOST/MISSING both land on UNDER_INSPECTION — "found" is the transient moment, not a resting state; see AssetStatus's own docblock. */
    public function markFound(Asset $asset, User $actor, ?string $notes = null): Asset
    {
        return DB::transaction(function () use ($asset, $actor, $notes) {
            /** @var Asset $asset */
            $asset = Asset::query()->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($asset->status, [AssetStatus::LOST, AssetStatus::MISSING], true)) {
                throw ValidationException::withMessages(['status' => 'Only a lost or missing asset can be marked found.']);
            }

            $this->assets->changeStatus($asset, AssetStatus::UNDER_INSPECTION, $actor, $notes);

            $this->audit->log(
                AuditAction::ASSET_FOUND,
                'Assets',
                $asset,
                description: "Asset {$asset->asset_number} found".($notes ? " — {$notes}" : ''),
                actor: $actor,
            );

            $this->history->log($asset, AssetHistoryEvent::FOUND, 'Found.'.($notes ? " {$notes}" : ''), actor: $actor);

            return $asset->refresh();
        });
    }

    private function closeOpenAssignment(Asset $asset): void
    {
        $open = AssetAssignment::query()->where('asset_id', $asset->getKey())->active()->first();

        $open?->update(['status' => AssetAssignment::RETURNED, 'returned_date' => now()]);
    }
}
