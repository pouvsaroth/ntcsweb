<?php

declare(strict_types=1);

namespace App\Services\Assets;

use App\Models\Asset;
use App\Models\AssetHistory;
use App\Models\User;

/**
 * Writes to `asset_history` — the business narrative of "what happened to
 * this asset" (assigned, transferred, repaired, retired...). Deliberately
 * separate from AuditLogger, which answers "who did it in the system" — see
 * AssetHistory's own docblock. Every AssetService/AssetIssueService/
 * AssetRepairService/AssetMaintenanceService/AssetLifecycleService method
 * that changes an asset calls this alongside its AuditLogger call.
 */
final class AssetHistoryRecorder
{
    public function log(
        Asset $asset,
        string $eventType,
        string $description,
        ?array $oldValue = null,
        ?array $newValue = null,
        ?User $actor = null,
    ): AssetHistory {
        return AssetHistory::create([
            'asset_id' => $asset->getKey(),
            'event_type' => $eventType,
            'description' => $description,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'occurred_at' => now(),
            'actor_id' => $actor?->getKey(),
        ]);
    }
}
