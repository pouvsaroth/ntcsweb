<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AssetAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AssetAssignment
 */
class AssetAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'assignable_type' => $this->assignable_type,
            'assignable_id' => $this->assignable_id,
            'assignable_label' => $this->whenLoaded('assignable', fn () => $this->assignable?->auditDisplayName()),
            'assigned_by' => $this->assigned_by,
            'assigned_date' => $this->assigned_date?->toDateString(),
            'expected_return_date' => $this->expected_return_date?->toDateString(),
            'returned_date' => $this->returned_date?->toDateString(),
            'condition_at_assignment' => $this->condition_at_assignment,
            'condition_at_return' => $this->condition_at_return,
            'status' => $this->status,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
