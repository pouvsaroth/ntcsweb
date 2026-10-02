<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MakeUpClassRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shared by both the admin list and student self-service ("my requests") —
 * the `student` block is simply omitted when the caller didn't eager-load it.
 *
 * @mixin MakeUpClassRequest
 */
class MakeUpClassRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student' => $this->whenLoaded('student', fn () => $this->student !== null ? [
                'id' => $this->student->id,
                'name' => $this->student->fullName(),
                'student_code' => $this->student->student_code,
            ] : null),
            'enrollment_id' => $this->enrollment_id,
            'course_package' => $this->whenLoaded('enrollment', fn () => $this->enrollment?->coursePackage !== null
                ? ['id' => $this->enrollment->coursePackage->id, 'name' => $this->enrollment->coursePackage->name]
                : null),
            'school_class' => $this->whenLoaded('enrollment', fn () => $this->enrollment?->schoolClass !== null
                ? ['id' => $this->enrollment->schoolClass->id, 'name' => $this->enrollment->schoolClass->name]
                : null),
            'from_date' => $this->from_date?->toDateString(),
            'to_date' => $this->to_date?->toDateString(),
            'from_time' => $this->from_time !== null ? substr((string) $this->from_time, 0, 5) : null,
            'to_time' => $this->to_time !== null ? substr((string) $this->to_time, 0, 5) : null,
            'status' => $this->status,
            // Approvals queue only — see ApprovalFlow::progress().
            'approval_flow' => $this->whenLoaded('approvalFlow'),
            'decision_reason' => $this->decision_reason,
            'decided_by' => $this->whenLoaded('decidedBy', fn () => $this->decidedBy?->name),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
