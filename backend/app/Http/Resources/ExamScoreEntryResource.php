<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ExamApplication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Grades-tab row: an approved exam application plus its score (null
 * until one is entered). Expects ExamScoreService::WITH to be loaded.
 *
 * @mixin ExamApplication
 */
class ExamScoreEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'exam_application_id' => $this->id,
            'enrollment_code' => $this->enrollment?->enrollments_code,
            'student' => $this->student !== null ? [
                'id' => $this->student->id,
                'student_code' => $this->student->student_code,
                'name' => $this->student->fullName(),
                'english_name' => $this->student->english_name,
                'gender' => $this->student->gender,
            ] : null,
            'course_package' => $this->enrollment?->coursePackage !== null
                ? ['id' => $this->enrollment->coursePackage->id, 'name' => $this->enrollment->coursePackage->name]
                : null,
            'school_class' => $this->enrollment?->schoolClass !== null
                ? ['id' => $this->enrollment->schoolClass->id, 'name' => $this->enrollment->schoolClass->name]
                : null,
            'book' => $this->book !== null ? ['id' => $this->book->id, 'title' => $this->book->title] : null,
            'exam_date' => $this->exam_date?->toDateString(),
            'score' => $this->score?->score,
            'remark' => $this->score?->remark,
            'recorded_by' => $this->score?->recordedBy?->name,
            'recorded_at' => $this->score?->recorded_at?->toIso8601String(),
            // Whether ExamScoreService::record() already generated a
            // STATUS_MAKE_UP application from this row — drives the Grades
            // tab's Make-up Exam checkbox (checked + disabled once true).
            'has_make_up' => $this->retake !== null,
        ];
    }
}
