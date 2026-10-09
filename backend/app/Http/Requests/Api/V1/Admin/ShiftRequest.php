<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create/update a shift — the permission check is the controller's authorize(). */
class ShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $requiredWithoutDays = $this->isMethod('POST') ? 'required_without:days' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:20', Rule::unique('tenant.shifts', 'code')->ignore($this->route('shift'))],
            'name' => [$required, 'string', 'max:255'],
            // Its fallback hours — taken from the first day row when `days` is sent (see ShiftController::fill()).
            'start_time' => [$requiredWithoutDays, 'date_format:H:i'],
            'end_time' => [$requiredWithoutDays, 'date_format:H:i', 'different:start_time'],
            // Day / From / To rows, like a class schedule — one per weekday (ISO 1 = Monday … 7 = Sunday).
            'days' => ['sometimes', 'array', 'min:1', 'max:7'],
            'days.*.day_of_week' => ['required', 'integer', 'between:1,7', 'distinct'],
            'days.*.start_time' => ['required', 'date_format:H:i'],
            'days.*.end_time' => ['required', 'date_format:H:i', 'different:days.*.start_time'],
            'break_minutes' => ['sometimes', 'integer', 'min:0', 'max:600'],
            'late_grace_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'early_leave_grace_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
