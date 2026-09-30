<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Identity-gated, not permission-gated — any signed-in student may submit a
 * make-up class request for themselves. See MyMakeUpClassRequestController's
 * docblock, same pattern as StoreMyLeaveRequestRequest.
 */
class StoreMyMakeUpClassRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->student !== null;
    }

    public function rules(): array
    {
        // Only the student's own active enrollments — same rule as
        // StoreMyExamApplicationRequest's enrollment_id.
        $enrollmentIds = $this->user()->student->enrollments()->active()->pluck('id');

        return [
            'enrollment_id' => ['required', 'integer', Rule::in($enrollmentIds)],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'from_time' => ['required', 'date_format:H:i'],
            'to_time' => ['required', 'date_format:H:i', 'after:from_time'],
        ];
    }
}
