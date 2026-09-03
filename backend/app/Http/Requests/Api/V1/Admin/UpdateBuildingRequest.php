<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Building;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBuildingRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Building $building */
        $building = $this->route('building');

        return $this->user()?->can('update', $building) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();
        $building = $this->route('building');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('buildings')->where('tenant_id', $tenantId)->ignore($building),
            ],
            'code' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in([Building::STATUS_ACTIVE, Building::STATUS_INACTIVE])],
        ];
    }
}
