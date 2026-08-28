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
            'name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'string', 'max:10'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'guardian_name' => ['nullable', 'string', 'max:255'],
            'guardian_phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:500'],
            'enrollment_date' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in([
                Student::STATUS_ACTIVE, Student::STATUS_GRADUATED, Student::STATUS_WITHDRAWN, Student::STATUS_INACTIVE,
            ])],
            'user_id' => [
                'nullable',
                Rule::exists('users', 'id')->where('tenant_id', $tenantId),
                Rule::unique('students', 'user_id')->where('tenant_id', $tenantId),
            ],
        ];
    }
}
