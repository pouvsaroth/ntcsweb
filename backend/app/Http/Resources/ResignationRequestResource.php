<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ResignationRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shared by both the admin list and staff self-service ("my requests") —
 * the same shape is useful to both; the `staff` block is simply omitted
 * when the caller didn't eager-load it.
 *
 * @mixin ResignationRequest
 */
class ResignationRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'staff' => $this->whenLoaded('staff', fn () => $this->staff !== null ? [
                'id' => $this->staff->id,
                'name' => $this->staff->fullName(),
                'gender' => $this->staff->gender,
                'position' => $this->staff->relationLoaded('position') ? $this->staff->position?->name : null,
            ] : null),
            'resignation_date' => $this->resignation_date?->toDateString(),
            'reason' => $this->reason,
            'status' => $this->status,
            'decision_reason' => $this->decision_reason,
            'decided_by' => $this->whenLoaded('decidedBy', fn () => $this->decidedBy?->name),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
