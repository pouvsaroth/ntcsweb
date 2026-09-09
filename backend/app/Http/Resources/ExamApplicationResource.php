<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ExamApplication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shared by both the admin queue and student self-service ("my exam
 * applications") — the same shape is useful to both; the `student`/
 * `enrollment`/`decided_by` blocks are simply omitted when the caller
 * didn't eager-load them.
 *
 * @mixin ExamApplication
 */
class ExamApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student' => $this->whenLoaded('student', fn () => [
                'id' => $this->student->id,
                'student_code' => $this->student->student_code,
                'name' => $this->student->fullName(),
            ]),
            'enrollment' => $this->whenLoaded('enrollment', fn () => [
                'id' => $this->enrollment->id,
                'course_package' => $this->enrollment->relationLoaded('coursePackage') && $this->enrollment->coursePackage !== null
                    ? ['id' => $this->enrollment->coursePackage->id, 'name' => $this->enrollment->coursePackage->name]
                    : null,
                'school_class' => $this->enrollment->relationLoaded('schoolClass') && $this->enrollment->schoolClass !== null
                    ? ['id' => $this->enrollment->schoolClass->id, 'name' => $this->enrollment->schoolClass->name]
                    : null,
            ]),
            'exam_date' => $this->exam_date?->toDateString(),
            'exam_time' => $this->exam_time,
            'table_no' => $this->table_no,
            'fee_amount' => $this->fee_amount,
            'fee_currency' => $this->fee_currency,
            'student_marked_paid_at' => $this->student_marked_paid_at?->toIso8601String(),
            'status' => $this->status,
            'decision_reason' => $this->decision_reason,
            'decided_by' => $this->whenLoaded('decidedBy', fn () => $this->decidedBy?->name),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
