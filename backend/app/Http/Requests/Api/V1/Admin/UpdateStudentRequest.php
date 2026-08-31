<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Student;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Student $student */
        $student = $this->route('student');

        return $this->user()?->can('update', $student) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();
        $student = $this->route('student');

        return [
            // Not editable through the normal edit form — a Student ID is an
            // official, generated identifier (see StudentIdGenerator); no
            // rule here means a client-submitted `student_code` is silently
            // dropped by FormRequest::validated(), same treatment as a
            // system Role's locked name/level (see UpdateRoleRequest).
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'english_name' => ['nullable', 'string', 'max:255'],

            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'string', 'max:10'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],

            'house_no' => ['nullable', 'string', 'max:10'],
            'street_no' => ['nullable', 'string', 'max:10'],
            'village_code' => ['nullable', 'string', 'max:20'],
            'other_address' => ['nullable', 'string', 'max:150'],

            'facebook' => ['nullable', 'string', 'max:255'],
            'telegram' => ['nullable', 'string', 'max:255'],

            // Optional on update — see UpdateHomeSlideRequest for why (an
            // edit that doesn't touch the photo shouldn't have to re-upload it).
            'photo' => ['sometimes', 'image', 'mimes:jpeg,png,webp,gif', 'max:10240'],

            'enrollment_date' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in([
                Student::STATUS_ACTIVE, Student::STATUS_GRADUATED, Student::STATUS_WITHDRAWN, Student::STATUS_INACTIVE,
            ])],
            'user_id' => [
                'nullable',
                Rule::exists('users', 'id')->where('tenant_id', $tenantId),
                Rule::unique('students', 'user_id')->where('tenant_id', $tenantId)->ignore($student),
            ],

            // When provided, replaces this student's entire guardian/education
            // list — see StudentController::update(). Omitting the key
            // entirely leaves the existing rows untouched.
            'guardians' => ['sometimes', 'array'],
            'guardians.*.guardian_name' => ['required', 'string', 'max:100'],
            'guardians.*.guardian_type' => ['required', 'string', 'max:50'],
            'guardians.*.address' => ['nullable', 'string', 'max:200'],
            'guardians.*.phone' => ['required', 'string', 'max:50'],
            'guardians.*.email' => ['nullable', 'email:rfc', 'max:50'],
            'guardians.*.remark' => ['nullable', 'string', 'max:500'],

            'educations' => ['sometimes', 'array'],
            'educations.*.school_name' => ['required', 'string', 'max:200'],
            'educations.*.address' => ['required', 'string', 'max:225'],
            'educations.*.start_date' => ['required', 'date'],
            'educations.*.end_date' => ['nullable', 'date'],
            'educations.*.skill' => ['required', 'string', 'max:200'],
            'educations.*.detail' => ['required', 'string', 'max:500'],
        ];
    }
}
