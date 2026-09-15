<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ExamApplication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shared by both the admin queue/grid and student self-service ("my exam
 * applications") — the same shape is useful to both; the `student`/
 * `enrollment`/`decided_by` blocks are simply omitted when the caller
 * didn't eager-load them. `enrollment_code` is the enrollment's own
 * `enrollments_code` (see ExamApplicationService::lookupByEnrollmentCode())
 * — not a separate id generated for this resource.
 *
 * @mixin ExamApplication
 */
class ExamApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'enrollment_code' => $this->whenLoaded('enrollment', fn () => $this->enrollment?->enrollments_code),
            'student' => $this->whenLoaded('student', fn () => [
                'id' => $this->student->id,
                'student_code' => $this->student->student_code,
                'name' => $this->student->fullName(),
                'english_name' => $this->student->english_name,
                'gender' => $this->student->gender,
                'date_of_birth' => $this->student->date_of_birth?->toDateString(),
                'phone' => $this->student->phone,
                'address' => $this->student->fullAddress(),
                'photo_url' => $this->student->photoUrl(),
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
            'file_code' => $this->file_code,
            'book' => $this->whenLoaded('book', fn () => $this->book !== null ? ['id' => $this->book->id, 'title' => $this->book->title] : null),
            'exam_date' => $this->exam_date?->toDateString(),
            'exam_time' => $this->exam_time,
            'exam_time_out' => $this->exam_time_out,
            'table_no' => $this->table_no,
            'classroom' => $this->whenLoaded('classroom', fn () => $this->classroom !== null ? ['id' => $this->classroom->id, 'name' => $this->classroom->name] : null),
            'table' => $this->whenLoaded('table', fn () => $this->table !== null ? ['id' => $this->table->id, 'name' => $this->table->name] : null),
            'fee_amount' => $this->fee_amount,
            'fee_currency' => $this->fee_currency,
            'student_marked_paid_at' => $this->student_marked_paid_at?->toIso8601String(),
            'status' => $this->status,
            'decision_reason' => $this->decision_reason,
            'decided_by' => $this->whenLoaded('decidedBy', fn () => $this->decidedBy?->name),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'remark' => $this->remark,
            'sold_at' => $this->sold_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'paid_back_at' => $this->paid_back_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
