<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Asset
 */
class AssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_number' => $this->asset_number,
            'name' => $this->name,
            'description' => $this->description,
            'brand' => $this->brand,
            'model' => $this->model,
            'serial_number' => $this->serial_number,
            'asset_tag' => $this->asset_tag,
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category !== null ? [
                'id' => $this->category->id,
                'code' => $this->category->code,
                'name' => $this->category->name,
            ] : null),
            'purchase_date' => $this->purchase_date?->toDateString(),
            'purchase_price' => (float) $this->purchase_price,
            'current_value' => $this->current_value !== null ? (float) $this->current_value : null,
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier !== null ? ['id' => $this->supplier->id, 'name' => $this->supplier->name] : null),
            'warranty_start_date' => $this->warranty_start_date?->toDateString(),
            'warranty_end_date' => $this->warranty_end_date?->toDateString(),
            'warranty_provider' => $this->warranty_provider,
            'warranty_number' => $this->warranty_number,
            'warranty_is_active' => $this->warrantyIsActive(),
            'location_id' => $this->location_id,
            'location' => $this->whenLoaded('location', fn () => $this->location !== null ? ['id' => $this->location->id, 'code' => $this->location->code, 'name' => $this->location->name] : null),
            'department_id' => $this->department_id,
            'department' => $this->whenLoaded('department', fn () => $this->department !== null ? ['id' => $this->department->id, 'name' => $this->department->name] : null),
            'status' => $this->status,
            'condition' => $this->condition,
            'hostname' => $this->hostname,
            'mac_address' => $this->mac_address,
            'ip_address' => $this->ip_address,
            'specs' => $this->specs,
            'disposal_date' => $this->disposal_date?->toDateString(),
            'disposal_reason' => $this->disposal_reason,
            'disposal_method' => $this->disposal_method,
            'disposal_value' => $this->disposal_value !== null ? (float) $this->disposal_value : null,
            'current_assignment' => $this->whenLoaded('currentAssignment', fn () => $this->currentAssignment->isNotEmpty()
                ? new AssetAssignmentResource($this->currentAssignment->first())
                : null),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
