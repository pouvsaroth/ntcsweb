<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Applicant;
use App\Models\ApplicantDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * HR adding or editing an applicant — the permission check is the
 * controller's authorize() against RecruitmentPolicy. A CV may come with a
 * new one; more files go through ApplicantDocumentController.
 */
class ApplicantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'job_position_id' => ['nullable', 'integer', Rule::exists('tenant.job_positions', 'id')->whereNull('deleted_at')],
            'first_name' => [$required, 'string', 'max:255'],
            'last_name' => [$required, 'string', 'max:255'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'phone' => [$required, 'string', 'max:32'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'source' => [$required, Rule::in(Applicant::SOURCES)],
            'expected_salary' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'available_from' => ['nullable', 'date'],
            'cover_letter' => ['nullable', 'string', 'max:5000'],
            'stage' => ['sometimes', Rule::in(Applicant::STAGES)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'cv' => ['nullable', 'file', 'mimes:'.implode(',', ApplicantDocument::EXTENSIONS), 'max:'.ApplicantDocument::MAX_KB],
        ];
    }
}
