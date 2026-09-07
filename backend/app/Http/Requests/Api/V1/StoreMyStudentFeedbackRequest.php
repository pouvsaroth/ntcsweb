<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\StudentFeedback;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Identity-gated, not permission-gated — any signed-in student may submit a
 * request or comment for themselves. See MyStudentFeedbackController's
 * docblock, same pattern as StoreMyLeaveRequestRequest.
 */
class StoreMyStudentFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->student !== null;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in([StudentFeedback::TYPE_REQUEST, StudentFeedback::TYPE_COMMENT])],
            'topic' => ['required', Rule::in([StudentFeedback::TOPIC_SCHOOL, StudentFeedback::TOPIC_TEACHER])],
            'teacher_id' => [
                Rule::requiredIf(fn () => $this->input('topic') === StudentFeedback::TOPIC_TEACHER),
                'nullable',
                Rule::in($this->user()->student->teacherIds()),
            ],
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:2000'],
        ];
    }
}
