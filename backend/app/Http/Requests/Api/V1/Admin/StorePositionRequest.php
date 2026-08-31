<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Position;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Position::class) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('positions')->where('tenant_id', $tenantId)],

            // Must be one of this school's own roles — a Position can never
            // grant a platform role (super-admin) or another school's role.
            'role_id' => ['required', Rule::exists('roles', 'id')->where('tenant_id', $tenantId)],

            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::in([Position::STATUS_ACTIVE, Position::STATUS_INACTIVE])],
        ];
    }
}
