<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Role;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Role::class) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            // Slugified for the uniqueness check — this is exactly what
            // RoleController@store will store as `slug`, so a name that only
            // differs from an existing role's by case/punctuation is still
            // caught here rather than surfacing as a confusing DB error.
            'name' => [
                'required', 'string', 'max:255',
                function (string $attribute, mixed $value, \Closure $fail) use ($tenantId) {
                    $exists = Role::query()
                        ->where('tenant_id', $tenantId)
                        ->where('slug', Str::slug($value))
                        ->exists();

                    if ($exists) {
                        $fail(__('A role with this name already exists.'));
                    }
                },
            ],
            'description' => ['nullable', 'string', 'max:1000'],

            // Capped below the acting admin's own level: defense in depth on
            // top of RolePolicy's outranks() check, which only guards
            // update/delete/assign. Without this, create() would let anyone
            // holding roles.create mint a role that outranks its own creator
            // (though it would still carry no permissions unless separately
            // granted — see RoleController@store).
            'level' => [
                'required', 'integer', 'min:0',
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
