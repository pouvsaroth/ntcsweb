<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ManpowerRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create/update of a manpower request — the permission/status check itself
 * is ManpowerRequestPolicy, via the controller's authorize().
 */
class ManpowerRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'department_id' => ['nullable', 'integer', Rule::exists('tenant.departments', 'id')],
            'position_id' => ['nullable', 'integer', Rule::exists('tenant.positions', 'id')->whereNull('deleted_at')],
            'job_title' => [$required, 'string', 'max:255'],
            'headcount' => [$required, 'integer', 'min:1', 'max:500'],
            'employment_type' => [$required, Rule::in(ManpowerRequest::EMPLOYMENT_TYPES)],
            'needed_by' => ['nullable', 'date'],
            'reason' => [$required, 'string', 'max:2000'],
            'requirements' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
