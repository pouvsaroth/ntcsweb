<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\StaffStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StaffStatusHistory
 */
class StaffStatusHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'staff' => $this->whenLoaded('staff', fn () => [
                'id' => $this->staff->id,
                'employee_code' => $this->staff->employee_code,
                'full_name' => $this->staff->fullName(),
            ]),
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'reason' => $this->reason,
            'requested_date' => $this->requested_date?->toDateString(),
            'effective_date' => $this->effective_date?->toDateString(),
            'changed_by' => $this->whenLoaded('changedBy', fn () => $this->changedBy?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
