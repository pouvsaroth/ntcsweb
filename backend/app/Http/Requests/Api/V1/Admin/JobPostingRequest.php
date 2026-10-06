<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\JobPosting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create/update a job posting — the permission check is the controller's
 * authorize() against RecruitmentPolicy.
 */
class JobPostingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'job_position_id' => [$required, 'integer', Rule::exists('tenant.job_positions', 'id')->whereNull('deleted_at')],
            'channel' => [$required, Rule::in(JobPosting::CHANNELS)],
            'url' => ['nullable', 'url', 'max:500'],
            'posted_on' => [$required, 'date'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:posted_on'],
            'is_active' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
