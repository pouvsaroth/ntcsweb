<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Teacher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTeacherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Teacher::class) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'employee_code' => ['required', 'string', 'max:32', Rule::unique('teachers')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'specialization' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:5000'],
            'hire_date' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in([Teacher::STATUS_ACTIVE, Teacher::STATUS_INACTIVE])],

            // Only a user already in this school may be linked — never taken
            // on faith from the request, verified against the tenant-scoped
            // provider so a school can't link an account it doesn't own.
            'user_id' => [
                'nullable',
                Rule::exists('users', 'id')->where('tenant_id', $tenantId),
                Rule::unique('teachers', 'user_id')->where('tenant_id', $tenantId),
            ],
        ];
    }
}
