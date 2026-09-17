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
 * Editing an existing account's profile fields, plus — for a standalone
 * account only — reassigning its role. `role_id` is prohibited for a
 * student-linked account: that role is always forced to Student (see
 * StoreUserRequest), so there is nothing to reassign. Granting a role still
 * goes through the same RolePolicy::assign outranking check as creation
 * (withValidator() below), so this can't be used to hand out a role the
 * acting admin does not outrank either.
 */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('user')) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();
        /** @var User $target */
        $target = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'role_id' => [
                'nullable',
                Rule::prohibitedIf(fn () => $target->student()->exists()),
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
