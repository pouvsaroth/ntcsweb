<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OvertimeRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OvertimeRequest
 */
class OvertimeRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference(),
            'staff_id' => $this->staff_id,
            'staff' => $this->whenLoaded('staff', fn () => $this->staff !== null ? [
                'id' => $this->staff->id,
                'name' => $this->staff->fullName(),
                'employee_code' => $this->staff->employee_code,
            ] : null),
            'date' => $this->date?->toDateString(),
            'minutes' => $this->minutes,
            'reason' => $this->reason,
            'status' => $this->status,
            'requested_by' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy?->name),
            // Approvals queue only — see ApprovalFlow::progress().
            'approval_flow' => $this->whenLoaded('approvalFlow'),
            'decision_reason' => $this->decision_reason,
            'decided_by' => $this->whenLoaded('decidedBy', fn () => $this->decidedBy?->name),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
