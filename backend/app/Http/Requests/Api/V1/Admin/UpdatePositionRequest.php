<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Position;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Position $position */
        $position = $this->route('position');

        return $this->user()?->can('update', $position) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();
        $position = $this->route('position');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('positions')->where('tenant_id', $tenantId)->ignore($position),
            ],
            'role_id' => ['sometimes', 'required', Rule::exists('roles', 'id')->where('tenant_id', $tenantId)],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::in([Position::STATUS_ACTIVE, Position::STATUS_INACTIVE])],
        ];
    }
}
