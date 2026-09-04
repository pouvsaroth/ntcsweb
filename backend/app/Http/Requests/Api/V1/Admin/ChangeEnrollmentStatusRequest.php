<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Enrollment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeEnrollmentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Enrollment $enrollment */
        $enrollment = $this->route('enrollment');

        return $this->user()?->can('changeStatus', $enrollment) ?? false;
    }

    public function rules(): array
    {
        $requiresReason = in_array($this->input('status'), Enrollment::STATUSES_REQUIRING_REASON, true);

        return [
            'status' => ['required', Rule::in(Enrollment::STATUSES_MANAGEABLE)],
            'reason' => [$requiresReason ? 'required' : 'nullable', 'string', 'max:2000'],
            'effective_date' => [$requiresReason ? 'required' : 'nullable', 'date'],
        ];
    }
}
