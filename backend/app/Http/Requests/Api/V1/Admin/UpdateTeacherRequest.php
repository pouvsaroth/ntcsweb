<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Teacher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeacherRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Teacher $teacher */
        $teacher = $this->route('teacher');

        return $this->user()?->can('update', $teacher) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();
        $teacher = $this->route('teacher');

        return [
            'employee_code' => [
                'sometimes', 'required', 'string', 'max:32',
                Rule::unique('teachers')->where('tenant_id', $tenantId)->ignore($teacher),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'specialization' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:5000'],
            'hire_date' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in([Teacher::STATUS_ACTIVE, Teacher::STATUS_INACTIVE])],
            'user_id' => [
                'nullable',
                Rule::exists('users', 'id')->where('tenant_id', $tenantId),
                Rule::unique('teachers', 'user_id')->where('tenant_id', $tenantId)->ignore($teacher),
            ],
        ];
    }
}
