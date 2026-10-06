<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ManpowerRequest;
use App\Models\OfferLetter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create/edit an offer letter — the permission check is the controller's
 * authorize() against RecruitmentPolicy. `status` changes go through it
 * too (see OfferLetterService::changeStatus()).
 */
class OfferLetterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'applicant_id' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'integer', Rule::exists('tenant.applicants', 'id')],
            'position_title' => [$required, 'string', 'max:255'],
            'department_id' => ['nullable', 'integer', Rule::exists('tenant.departments', 'id')],
            'employment_type' => [$required, Rule::in(ManpowerRequest::EMPLOYMENT_TYPES)],
            'salary' => [$required, 'numeric', 'min:0', 'max:9999999999'],
            'salary_currency' => ['sometimes', Rule::in(['USD', 'KHR'])],
            'start_date' => [$required, 'date'],
            'probation_months' => ['nullable', 'integer', 'min:0', 'max:24'],
            'expires_on' => ['nullable', 'date'],
            'benefits' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:10000'],
            'status' => ['sometimes', Rule::in(OfferLetter::STATUSES)],
        ];
    }
}
