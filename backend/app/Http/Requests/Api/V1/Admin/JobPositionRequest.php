<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\JobPosition;
use App\Models\ManpowerRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create/update a job position — the permission check is the controller's
 * authorize() against RecruitmentPolicy. Only an approved manpower request
 * can be the one a job is opened from.
 */
class JobPositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'manpower_request_id' => ['nullable', 'integer', Rule::exists('tenant.manpower_requests', 'id')
                ->where('status', ManpowerRequest::STATUS_APPROVED)->whereNull('deleted_at')],
            'title' => [$required, 'string', 'max:255'],
            'department_id' => ['nullable', 'integer', Rule::exists('tenant.departments', 'id')],
            'position_id' => ['nullable', 'integer', Rule::exists('tenant.positions', 'id')->whereNull('deleted_at')],
            'branch_id' => ['nullable', 'integer', Rule::exists('tenant.branches', 'id')],
            'headcount' => [$required, 'integer', 'min:1', 'max:500'],
            'employment_type' => [$required, Rule::in(ManpowerRequest::EMPLOYMENT_TYPES)],
            'salary_min' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'salary_max' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'gte:salary_min'],
            'salary_currency' => ['sometimes', Rule::in(['USD', 'KHR'])],
            'description' => ['nullable', 'string', 'max:10000'],
            'requirements' => ['nullable', 'string', 'max:10000'],
            'status' => ['sometimes', Rule::in(JobPosition::STATUSES)],
            'opened_on' => ['nullable', 'date'],
            'closes_on' => ['nullable', 'date', 'after_or_equal:opened_on'],
        ];
    }
}
