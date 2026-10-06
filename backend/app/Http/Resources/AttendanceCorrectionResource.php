<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AttendanceCorrection;
use App\Models\StaffAttendance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Carries what was recorded that day too, so a reviewer sees the change.
 *
 * @mixin AttendanceCorrection
 */
class AttendanceCorrectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $recorded = StaffAttendance::query()->where('staff_id', $this->staff_id)->whereDate('date', $this->date?->toDateString())->first();

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
            'check_in' => $this->check_in,
            'check_out' => $this->check_out,
            'recorded' => [
                'check_in_at' => $recorded?->check_in_at?->toIso8601String(),
                'check_out_at' => $recorded?->check_out_at?->toIso8601String(),
                'status' => $recorded?->status,
            ],
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
