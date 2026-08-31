<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Two shapes, chosen by whether `student_id` is present:
 *
 *   - linking an existing, not-yet-linked Student — this is how a
 *     bulk-imported student (never auto-provisioned, see StudentController)
 *     gets portal access later. The role is always that tenant's Student
 *     role; `role_id` is not accepted in this shape.
 *   - a standalone account (e.g. an extra School Admin) — `role_id` is
 *     required, and is only accepted if the acting admin actually outranks
 *     it (checked in withValidator(), via the same RolePolicy::assign the
 *     explicit "assign a role" admin action already uses).
 *
 * Either way, phone is required — the same reasoning as
 * StoreStudentRequest/StoreStaffRequest: a school may have no email on file,
 * but always has a phone number.
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'student_id' => [
                'nullable',
                Rule::exists('students', 'id')->where('tenant_id', $tenantId)->whereNull('user_id'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'role_id' => [
                'required_without:student_id',
                'prohibited_if:student_id,present',
                Rule::exists('roles', 'id')->where('tenant_id', $tenantId),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $roleId = $this->input('role_id');

            if ($validator->errors()->isNotEmpty() || $roleId === null) {
                return;
            }

            $role = Role::query()->find($roleId);

            if ($role !== null && Gate::forUser($this->user())->denies('assign', $role)) {
                $validator->errors()->add('role_id', __('You may not grant a role you do not outrank.'));
            }
        });
    }
}
