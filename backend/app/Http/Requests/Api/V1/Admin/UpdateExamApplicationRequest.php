<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ExamApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * No `enrollment_id` field — deliberately: the legacy form only ever edits
 * an application's own exam-day logistics and status, never re-points it at
 * a different student/enrollment. See StoreExamApplicationRequest.
 */
class UpdateExamApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ExamApplication $examApplication */
        $examApplication = $this->route('exam_application');

        return $this->user()?->can('update', $examApplication) ?? false;
    }

    public function rules(): array
    {
        return [
            'file_code' => ['nullable', 'string', 'max:50'],
            'book_id' => ['nullable', Rule::exists('tenant.books', 'id')],
            'exam_date' => ['nullable', 'date'],
            'exam_time' => ['nullable', 'date_format:H:i'],
            'exam_time_out' => ['nullable', 'date_format:H:i'],
            'table_no' => ['nullable', 'string', 'max:20'],
            'classroom_id' => ['nullable', Rule::exists('tenant.classrooms', 'id')],
            'table_id' => ['nullable', Rule::exists('tenant.classroom_tables', 'id')],
            // Sometimes, not required: the redesigned Application Form has
            // no status selector — saving it never touches status, which
            // only ever changes via approve()/reject() or the "Exam
            // Application Approval" tab. Still accepted here for API
            // flexibility (e.g. a future direct status edit).
            'status' => ['sometimes', 'nullable', Rule::in([
                ExamApplication::STATUS_PENDING, ExamApplication::STATUS_APPROVED, ExamApplication::STATUS_REJECTED,
            ])],
            'remark' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        ExamApplicationTableValidation::ensureTableBelongsToClassroom($validator, $this);
    }
}
