<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AssetMaintenance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AssetMaintenance
 */
class AssetMaintenanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'maintenance_number' => $this->maintenance_number,
            'asset_id' => $this->asset_id,
            'asset' => $this->whenLoaded('asset', fn () => $this->asset !== null ? ['id' => $this->asset->id, 'asset_number' => $this->asset->asset_number, 'name' => $this->asset->name] : null),
            'maintenance_type' => $this->maintenance_type,
            'scheduled_date' => $this->scheduled_date?->toDateString(),
            'completed_date' => $this->completed_date?->toDateString(),
            'description' => $this->description,
            'repair_shop_id' => $this->repair_shop_id,
            'repair_shop' => $this->whenLoaded('repairShop', fn () => $this->repairShop !== null ? ['id' => $this->repairShop->id, 'name' => $this->repairShop->name] : null),
            'cost' => $this->cost !== null ? (float) $this->cost : null,
            'status' => $this->status,
            'is_overdue' => $this->isOverdue(),
            'recurrence_interval_months' => $this->recurrence_interval_months,
            'next_maintenance_date' => $this->next_maintenance_date?->toDateString(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
