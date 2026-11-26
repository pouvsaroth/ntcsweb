<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Role;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Role $role */
        $role = $this->route('role');

        return $this->user()?->can('update', $role) ?? false;
    }

    public function rules(): array
    {
        /** @var Role $role */
        $role = $this->route('role');

        $tenantId = app(TenantContext::class)->idOrFail();
        $name = [
            'sometimes', 'required', 'string', 'max:255',
            function (string $attribute, mixed $value, \Closure $fail) use ($tenantId, $role) {
                if (Role::nameTaken($tenantId, (string) $value, $role->getKey())) {
                    $fail(__('A role with this name already exists.'));
                }
            },
        ];

        // A built-in role may be renamed, but its slug and level stay — the
        // code finds it by slug (see RolePolicy::update()). No rule for a key
        // means FormRequest::validated() never returns it, so a `level` sent
        // with a built-in role edit is silently dropped.
        if ($role->is_system) {
            return [
                'name' => $name,
                'description' => ['nullable', 'string', 'max:1000'],
                // School Admin always sees everything; the other built-in roles may be limited.
                ...($role->slug === Role::SCHOOL_ADMIN ? [] : ['data_scope' => ['sometimes', Rule::in([Role::SCOPE_ALL, Role::SCOPE_ASSIGNED])]]),
                'permissions' => ['sometimes', 'array'],
                'permissions.*' => [Rule::exists('permissions', 'slug')],
            ];
        }

        return [
            'name' => $name,
            'description' => ['nullable', 'string', 'max:1000'],
            'data_scope' => ['sometimes', Rule::in([Role::SCOPE_ALL, Role::SCOPE_ASSIGNED])],
            'level' => [
                'sometimes', 'required', 'integer', 'min:0',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($value >= $this->user()->roleLevel()) {
                        $fail(__('A role\'s level must be lower than your own.'));
                    }
                },
            ],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => [Rule::exists('permissions', 'slug')],
        ];
    }
}
