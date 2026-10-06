<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\LeaveType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create/update a leave type (HRM > Leave Management > Leave types). */
class LeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:20', Rule::unique('tenant.leave_types', 'code')->ignore($this->route('leave_type'))],
            'name' => [$required, 'string', 'max:255'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_paid' => ['sometimes', 'boolean'],
            'allow_half_day' => ['sometimes', 'boolean'],
            'requires_attachment' => ['sometimes', 'boolean'],
            'gender' => ['nullable', Rule::in(LeaveType::GENDERS)],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
