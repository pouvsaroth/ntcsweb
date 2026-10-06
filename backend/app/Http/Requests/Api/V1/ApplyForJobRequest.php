<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\ApplicantDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The public Careers page's Apply form — anyone may send it (rate-limited
 * on the route), so a CV is required and nothing internal (stage, notes,
 * source) is accepted: those are set server-side.
 */
class ApplyForJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'phone' => ['required', 'string', 'max:32', 'regex:/^\D*(\d\D*){6,}$/'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'expected_salary' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'available_from' => ['nullable', 'date'],
            'cover_letter' => ['nullable', 'string', 'max:5000'],
            'cv' => ['required', 'file', 'mimes:'.implode(',', ApplicantDocument::EXTENSIONS), 'max:'.ApplicantDocument::MAX_KB],
        ];
    }
}
