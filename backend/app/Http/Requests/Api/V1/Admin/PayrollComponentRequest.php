<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\PayrollComponent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create/update a pay component (HRM > Payroll > Allowances / Bonuses / Deductions). */
class PayrollComponentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            // The kind never changes once used — an allowance doesn't become a deduction.
            'kind' => $this->isMethod('POST') ? ['required', Rule::in(PayrollComponent::KINDS)] : ['prohibited'],
            'code' => [$required, 'string', 'max:20', Rule::unique('tenant.payroll_components', 'code')->ignore($this->route('payroll_component'))],
            'name' => [$required, 'string', 'max:255'],
            'calculation' => ['sometimes', Rule::in(PayrollComponent::CALCULATIONS)],
            'affects_tax' => ['sometimes', 'boolean'],
            'affects_social_security' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
