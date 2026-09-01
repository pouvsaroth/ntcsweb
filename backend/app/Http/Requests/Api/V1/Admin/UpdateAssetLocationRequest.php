<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\AssetLocation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssetLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var AssetLocation $location */
        $location = $this->route('asset_location');

        return $this->user()?->can('update', $location) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();
        /** @var AssetLocation $location */
        $location = $this->route('asset_location');

        return [
            'code' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('asset_locations')->where('tenant_id', $tenantId)->ignore($location)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', Rule::in(AssetLocation::types())],
            'parent_id' => ['nullable', Rule::exists('asset_locations', 'id')->where('tenant_id', $tenantId), 'not_in:'.$location->getKey()],
            'classroom_id' => ['nullable', Rule::exists('classrooms', 'id')->where('tenant_id', $tenantId)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
