<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\SalaryStructure;
use App\Models\StaffSalary;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A staff member's basic salary from a date on (HRM > Payroll > Basic
 * salary). Creating one is a raise / change; updating corrects a row. One
 * row per staff member per effective date.
 */
class StaffSalaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';
        /** @var StaffSalary|null $existing */
        $existing = $this->route('staff_salary');
        $staffId = $creating ? $this->input('staff_id') : $existing?->staff_id;

        return [
            'staff_id' => $creating ? ['required', 'integer', Rule::exists('tenant.staff', 'id')] : ['prohibited'],
            'basic_salary' => [$required, 'numeric', 'min:0', 'max:9999999999'],
            'currency' => [$required, Rule::in([Tenant::CURRENCY_USD, Tenant::CURRENCY_KHR])],
            'salary_structure_id' => ['nullable', 'integer', Rule::exists('tenant.salary_structures', 'id')],
            'effective_from' => [
                $required,
                'date',
                Rule::unique('tenant.staff_salaries', 'effective_from')
                    ->where('staff_id', $staffId)
                    ->ignore($existing?->id),
            ],
            'payment_method' => ['sometimes', Rule::in(StaffSalary::PAYMENT_METHODS)],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'effective_from.unique' => 'This staff member already has a salary starting on this date. Edit that one instead.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || ! $this->filled('salary_structure_id')) {
                return;
            }

            /** @var StaffSalary|null $existing */
            $existing = $this->route('staff_salary');
            $currency = $this->input('currency', $existing?->currency);
            $structure = SalaryStructure::query()->find($this->integer('salary_structure_id'));

            // A structure's fixed amounts are in its own currency — paying
            // them to a salary in the other one would mix USD and riel.
            if ($structure !== null && $structure->currency !== $currency) {
                $validator->errors()->add('salary_structure_id', "This salary structure is in {$structure->currency}; pick one in {$currency}, or change the currency.");
            }
        });
    }
}
