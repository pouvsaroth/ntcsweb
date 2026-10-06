<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\LeaveRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Identity-gated, not permission-gated — any signed-in student or staff
 * member may file a leave request for themselves. See
 * MyLeaveRequestController's docblock.
 */
class StoreMyLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->student !== null || $this->user()?->staff !== null;
    }

    public function rules(): array
    {
        // Leave type and day part are a staff member's (HRM > Leave
        // Management); a student's request never has them.
        $student = $this->user()?->student !== null;

        return [
            'leave_type_id' => $student ? ['prohibited'] : ['nullable', 'integer'],
            'day_part' => $student ? ['prohibited'] : ['nullable', Rule::in(LeaveRequest::DAY_PARTS)],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'from_time' => ['nullable', 'date_format:H:i'],
            'to_time' => ['nullable', 'date_format:H:i', 'after:from_time'],
            'reason' => ['required', 'string', 'max:1000'],
            'attachments' => ['sometimes', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->filled('from_time') !== $this->filled('to_time')) {
                $validator->errors()->add('to_time', 'Both a from and to time are required together.');
            }
        });
    }
}
