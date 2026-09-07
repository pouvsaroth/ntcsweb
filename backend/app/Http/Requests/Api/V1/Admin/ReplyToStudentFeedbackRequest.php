<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\StudentFeedback;
use Illuminate\Foundation\Http\FormRequest;

class ReplyToStudentFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var StudentFeedback $studentFeedback */
        $studentFeedback = $this->route('student_feedback');

        return $this->user()?->can('reply', $studentFeedback) ?? false;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:2000'],
        ];
    }
}
