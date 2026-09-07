<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Identity-gated, not permission-gated — any signed-in student may submit
 * an exam application for one of their own enrollments. See
 * MyExamApplicationController's docblock, same pattern as
 * StoreMyLeaveRequestRequest.
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
            'exam_date' => ['required', 'date', 'after_or_equal:today'],
            'exam_time' => ['required', 'date_format:H:i'],
            'table_no' => ['required', 'string', 'max:20'],
            // The "I have paid the exam fee" checkbox — must be checked to
            // submit at all. See ExamApplicationService's docblock for why
            // this doesn't create a real Payment record itself.
            'has_paid' => ['required', 'accepted'],
        ];
    }
}
