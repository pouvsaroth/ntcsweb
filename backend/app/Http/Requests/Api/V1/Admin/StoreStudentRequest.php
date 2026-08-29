<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Student;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Student::class) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'student_code' => ['required', 'string', 'max:32', Rule::unique('students')->where('tenant_id', $tenantId)],

            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'english_name' => ['nullable', 'string', 'max:255'],

            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'string', 'max:10'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],

            // Structured to match the legacy system's address shape — see
            // the restructuring migration for the full field-by-field
            // correspondence.
            'house_no' => ['nullable', 'string', 'max:10'],
            'street_no' => ['nullable', 'string', 'max:10'],
            'village_code' => ['nullable', 'string', 'max:20'],
            'other_address' => ['nullable', 'string', 'max:150'],

            'facebook' => ['nullable', 'string', 'max:255'],
            'telegram' => ['nullable', 'string', 'max:255'],

            // Same shape as HomeSlide's upload — see StoreHomeSlideRequest.
            'photo' => ['nullable', 'image', 'mimes:jpeg,png,webp,gif', 'max:10240'],

            'enrollment_date' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in([
                Student::STATUS_ACTIVE, Student::STATUS_GRADUATED, Student::STATUS_WITHDRAWN, Student::STATUS_INACTIVE,
            ])],
            'user_id' => [
                'nullable',
                Rule::exists('users', 'id')->where('tenant_id', $tenantId),
                Rule::unique('students', 'user_id')->where('tenant_id', $tenantId),
            ],

            // A student can have more than one guardian (father, mother,
            // other) — see the student_guardians migration. Optional: a
            // school may not have this on hand at registration time.
            'guardians' => ['nullable', 'array'],
            'guardians.*.guardian_name' => ['required', 'string', 'max:100'],
            'guardians.*.guardian_type' => ['required', 'string', 'max:50'],
            'guardians.*.address' => ['nullable', 'string', 'max:200'],
            'guardians.*.phone' => ['required', 'string', 'max:50'],
            'guardians.*.email' => ['nullable', 'email:rfc', 'max:50'],
            'guardians.*.remark' => ['nullable', 'string', 'max:500'],

            // Prior schools attended — see the student_educations migration.
            'educations' => ['nullable', 'array'],
            'educations.*.school_name' => ['required', 'string', 'max:200'],
            'educations.*.address' => ['required', 'string', 'max:225'],
            'educations.*.start_date' => ['required', 'date'],
            'educations.*.end_date' => ['nullable', 'date'],
            'educations.*.skill' => ['required', 'string', 'max:200'],
            'educations.*.detail' => ['required', 'string', 'max:500'],
        ];
    }
}
