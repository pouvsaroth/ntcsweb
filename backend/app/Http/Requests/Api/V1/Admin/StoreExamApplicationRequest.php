<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ExamApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Admin-created exam application, reached from the "Exam Application" tab's
 * form after looking a student up by their Enrollment Code — see
 * ExamApplicationController::lookup(). Unlike StoreMyExamApplicationRequest
 * (the student self-service path), nothing here is required beyond which
 * enrollment and which status: exam-day logistics are routinely decided
 * later, see the 2026_09_15_030000 migration's docblock.
 */
class StoreExamApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ExamApplication::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'enrollment_id' => [
                'required',
                Rule::exists('tenant.enrollments', 'id'),
                // At most one exam application per enrollment, ever, in any
                // status — an enrollment that's already applied (pending,
                // approved, or rejected) can't apply again. The roster's
                // bulk "Send to Exam" (ClassStudents.vue) is the easiest way
                // to double-submit the same student by accident.
                Rule::unique('tenant.exam_applications', 'enrollment_id')->whereNull('deleted_at'),
            ],
            'file_code' => ['nullable', 'string', 'max:50'],
            'book_id' => ['nullable', Rule::exists('tenant.books', 'id')],
            'exam_date' => ['nullable', 'date'],
            'exam_time' => ['nullable', 'date_format:H:i'],
            'exam_time_out' => ['nullable', 'date_format:H:i'],
            'table_no' => ['nullable', 'string', 'max:20'],
            'classroom_id' => ['nullable', Rule::exists('tenant.classrooms', 'id')],
            'table_id' => ['nullable', Rule::exists('tenant.classroom_tables', 'id')],
            // Nullable, not required: the Application Form has no status
            // selector at all — a new admin-created application simply
            // starts PENDING (the model's own default) like a student's own
            // submission does. The roster's bulk "Send to Exam"
            // (SendToExamModal.vue) is the one caller that does pass a
            // status explicitly — DRAFT, since nobody has actually applied
            // yet at that point (see ExamApplicationService::applyOnline()'s
            // docblock). Status otherwise only ever changes afterward via
            // approve()/reject()/markNotExam() or the "Exam Application
            // Approval" tab.
            'status' => ['sometimes', 'nullable', Rule::in([
                ExamApplication::STATUS_DRAFT, ExamApplication::STATUS_PENDING,
                ExamApplication::STATUS_APPROVED, ExamApplication::STATUS_REJECTED, ExamApplication::STATUS_NOT_EXAM,
            ])],
            'remark' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        ExamApplicationTableValidation::ensureTableBelongsToClassroom($validator, $this);
    }

    public function messages(): array
    {
        return [
            'enrollment_id.unique' => __('This enrollment already has an exam application.'),
        ];
    }
}
