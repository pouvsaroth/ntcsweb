<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create/update a work schedule: the shift for each weekday (empty = day
 * off) and, optionally, the staff who follow it (`staff_ids` replaces the
 * whole list when sent).
 */
class WorkScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'monday_shift_id' => ['nullable', 'integer', Rule::exists('tenant.shifts', 'id')],
            'tuesday_shift_id' => ['nullable', 'integer', Rule::exists('tenant.shifts', 'id')],
            'wednesday_shift_id' => ['nullable', 'integer', Rule::exists('tenant.shifts', 'id')],
            'thursday_shift_id' => ['nullable', 'integer', Rule::exists('tenant.shifts', 'id')],
            'friday_shift_id' => ['nullable', 'integer', Rule::exists('tenant.shifts', 'id')],
            'saturday_shift_id' => ['nullable', 'integer', Rule::exists('tenant.shifts', 'id')],
            'sunday_shift_id' => ['nullable', 'integer', Rule::exists('tenant.shifts', 'id')],
            'is_default' => ['sometimes', 'boolean'],
            'staff_ids' => ['sometimes', 'array'],
            'staff_ids.*' => ['integer', 'distinct', Rule::exists('tenant.staff', 'id')->whereNull('deleted_at')],
        ];
    }
}
