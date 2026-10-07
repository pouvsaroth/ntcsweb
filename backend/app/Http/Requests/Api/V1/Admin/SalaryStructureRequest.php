<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\PayrollComponent;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create/update a salary structure (HRM > Payroll > Salary structure) and
 * its items — `items` replaces the whole list when sent.
 */
class SalaryStructureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:20', Rule::unique('tenant.salary_structures', 'code')->ignore($this->route('salary_structure'))],
            'name' => [$required, 'string', 'max:255'],
            'currency' => [$required, Rule::in([Tenant::CURRENCY_USD, Tenant::CURRENCY_KHR])],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'items' => ['sometimes', 'array', 'max:50'],
            'items.*.payroll_component_id' => ['required', 'integer', 'distinct', Rule::exists('tenant.payroll_components', 'id')],
            'items.*.amount' => ['required', 'numeric', 'min:0', 'max:9999999999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // A percentage of basic salary can't be more than all of it.
            $percentIds = PayrollComponent::query()
                ->whereIn('id', collect($this->input('items', []))->pluck('payroll_component_id'))
                ->where('calculation', PayrollComponent::CALCULATION_PERCENT_OF_BASIC)
                ->pluck('id')
                ->all();

            foreach ($this->input('items', []) as $index => $item) {
                if (in_array((int) $item['payroll_component_id'], $percentIds, true) && (float) $item['amount'] > 100) {
                    $validator->errors()->add("items.{$index}.amount", 'A percentage can be at most 100.');
                }
            }
        });
    }
}
