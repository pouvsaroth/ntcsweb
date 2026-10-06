<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ApplicantDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Uploading a file to an applicant — see ApplicantDocumentController. */
class ApplicantDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'applicant_id' => ['required', 'integer', Rule::exists('tenant.applicants', 'id')],
            'type' => ['required', Rule::in(ApplicantDocument::TYPES)],
            'file' => ['required', 'file', 'mimes:'.implode(',', ApplicantDocument::EXTENSIONS), 'max:'.ApplicantDocument::MAX_KB],
        ];
    }
}
