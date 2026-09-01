<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Asset;
use App\Support\Assets\AssetCondition;
use App\Support\Assets\AssetStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Asset numbers are never accepted from the request — AssetNumberGenerator assigns one server-side. */
class StoreAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Asset::class) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'category_id' => ['required', Rule::exists('asset_categories', 'id')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'brand' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255', Rule::unique('assets')->where('tenant_id', $tenantId)],
            'asset_tag' => ['nullable', 'string', 'max:64', Rule::unique('assets')->where('tenant_id', $tenantId)],
            'purchase_date' => ['nullable', 'date'],
            'purchase_price' => ['nullable', 'numeric', 'min:0', 'max:99999999999999.99'],
            'current_value' => ['nullable', 'numeric', 'min:0', 'max:99999999999999.99'],
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('tenant_id', $tenantId)],
            'warranty_start_date' => ['nullable', 'date'],
            'warranty_end_date' => ['nullable', 'date', 'after_or_equal:warranty_start_date'],
            'warranty_provider' => ['nullable', 'string', 'max:255'],
            'warranty_number' => ['nullable', 'string', 'max:100'],
            'location_id' => ['nullable', Rule::exists('asset_locations', 'id')->where('tenant_id', $tenantId)],
            'department_id' => ['nullable', Rule::exists('departments', 'id')->where('tenant_id', $tenantId)],
            'status' => ['sometimes', Rule::in(AssetStatus::all())],
            'condition' => ['sometimes', Rule::in(AssetCondition::all())],
            'hostname' => ['nullable', 'string', 'max:100'],
            'mac_address' => ['nullable', 'string', 'max:32'],
            'ip_address' => ['nullable', 'ip'],
            'specs' => ['sometimes', 'array'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
