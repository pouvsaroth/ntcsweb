<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\StudentFeedback;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Identity-gated — a student may only follow up on their own thread. See
 * MyStudentFeedbackController::storeReply().
 */
class StoreMyStudentFeedbackReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var StudentFeedback $studentFeedback */
        $studentFeedback = $this->route('student_feedback');

        return $studentFeedback->student_id === $this->user()?->student?->id;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:2000'],
        ];
    }
}
