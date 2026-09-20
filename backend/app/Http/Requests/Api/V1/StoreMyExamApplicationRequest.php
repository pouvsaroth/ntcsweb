<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\ExamApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Identity-gated, not permission-gated — any signed-in student may apply
 * for one of their own enrollments. See MyExamApplicationController's
 * docblock, same pattern as StoreMyLeaveRequestRequest.
 *
 * No exam_date/exam_time/table_no here any more — the student never sets
 * exam-day logistics, only a teacher/admin does (see
 * ExamApplicationService::applyOnline()'s docblock). The personal-info
 * fields mirror the admin Application Form's editable "Student
 * Information" section exactly (see UpdateStudentRequest's own rules for
 * these same fields).
 */
class StoreMyExamApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->student !== null;
    }

    public function rules(): array
    {
        $enrollmentIds = $this->user()->student->enrollments()->active()->pluck('id');

        return [
            'enrollment_id' => ['required', Rule::in($enrollmentIds)],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'english_name' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', 'string', 'max:10'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'phone' => ['nullable', 'string', 'max:32'],
            'village_code' => ['nullable', 'string', 'max:20'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,png,webp,gif', 'max:10240'],
            // The "I have paid the exam fee" checkbox — informational only,
            // not a gate: whether or not it's checked, submission still goes
            // through and stamps student_marked_paid_at the same way (see
            // ExamApplicationService::applyOnline()), which is itself never
            // proof of real payment — the school still verifies and collects
            // the fee in person via the admin's Print flow. Requiring it
            // just blocked students who hadn't paid yet from applying at all,
            // which defeats the point of letting them apply first and pay
            // later.
            'has_paid' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $enrollmentId = $this->input('enrollment_id');
            if ($validator->errors()->isNotEmpty() || $enrollmentId === null) {
                return;
            }

            // Mirrors StoreExamApplicationRequest's own rule: at most one
            // exam application per enrollment, ever — but unlike a fresh
            // create, resubmitting against an existing *draft* or *pending*
            // one (a teacher already sent this enrollment to exam) is
            // exactly the point of this endpoint, so only a *decided* one
            // (or Not Exam) blocks it.
            $existingStatus = ExamApplication::query()
                ->where('enrollment_id', $enrollmentId)
                ->whereNull('deleted_at')
                ->value('status');

            if (in_array($existingStatus, [ExamApplication::STATUS_APPROVED, ExamApplication::STATUS_REJECTED, ExamApplication::STATUS_NOT_EXAM], true)) {
                $validator->errors()->add('enrollment_id', __('This enrollment already has a decided exam application.'));
            }
        });
    }
}
