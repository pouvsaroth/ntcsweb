<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Building;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBuildingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Building::class) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('buildings')->where('tenant_id', $tenantId)],
            'code' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in([Building::STATUS_ACTIVE, Building::STATUS_INACTIVE])],
        ];
    }
}
