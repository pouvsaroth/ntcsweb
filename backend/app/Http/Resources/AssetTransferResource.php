<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AssetTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AssetTransfer
 */
class AssetTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'from_location' => $this->whenLoaded('fromLocation', fn () => $this->fromLocation?->name),
            'to_location' => $this->whenLoaded('toLocation', fn () => $this->toLocation?->name),
            'from_department' => $this->whenLoaded('fromDepartment', fn () => $this->fromDepartment?->name),
            'to_department' => $this->whenLoaded('toDepartment', fn () => $this->toDepartment?->name),
            'transferred_by' => $this->whenLoaded('transferredBy', fn () => $this->transferredBy?->name),
            'transfer_date' => $this->transfer_date?->toDateString(),
            'reason' => $this->reason,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
