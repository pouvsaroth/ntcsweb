<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\PayrollComponent;
use App\Models\StaffPayComponent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Give one staff member an allowance, bonus or deduction (HRM > Payroll >
 * Allowances / Bonuses / Deductions) — recurring between two dates, or once.
 */
class StaffPayComponentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'staff_id' => $creating ? ['required', 'integer', Rule::exists('tenant.staff', 'id')] : ['prohibited'],
            'payroll_component_id' => [$required, 'integer', Rule::exists('tenant.payroll_components', 'id')],
            'amount' => [$required, 'numeric', 'min:0', 'max:9999999999'],
            'recurrence' => ['sometimes', Rule::in(StaffPayComponent::RECURRENCES)],
            'starts_on' => [$required, 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var StaffPayComponent|null $existing */
            $existing = $this->route('staff_pay_component');
            $component = PayrollComponent::query()->find($this->input('payroll_component_id', $existing?->payroll_component_id));

            if ($component !== null && $component->calculation === PayrollComponent::CALCULATION_PERCENT_OF_BASIC && (float) $this->input('amount', $existing?->amount) > 100) {
                $validator->errors()->add('amount', 'A percentage can be at most 100.');
            }

            if ($this->input('recurrence', $existing?->recurrence) === StaffPayComponent::ONCE && $this->filled('ends_on')) {
                $validator->errors()->add('ends_on', 'A one-time item has no end date — it is paid in the payroll covering its date.');
            }
        });
    }
}
