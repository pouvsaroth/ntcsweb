<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Role;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
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

        // A system role's name/slug/level are locked — see RolePolicy::update()
        // for why. No rule for a key means FormRequest::validated() simply
        // never returns it, so a `name`/`level` submitted alongside a system
        // role edit is silently dropped rather than applied or rejected.
        if ($role->is_system) {
            return [
                'description' => ['nullable', 'string', 'max:1000'],
                'permissions' => ['sometimes', 'array'],
                'permissions.*' => [Rule::exists('permissions', 'slug')],
            ];
        }

        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                function (string $attribute, mixed $value, \Closure $fail) use ($tenantId, $role) {
                    $exists = Role::query()
                        ->where('tenant_id', $tenantId)
                        ->where('slug', Str::slug($value))
                        ->whereKeyNot($role->getKey())
                        ->exists();

                    if ($exists) {
                        $fail(__('A role with this name already exists.'));
                    }
                },
            ],
            'description' => ['nullable', 'string', 'max:1000'],
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
