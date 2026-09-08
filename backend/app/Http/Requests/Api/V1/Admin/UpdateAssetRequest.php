<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Deliberately excludes `status`, `condition`, `location_id`, `department_id`
 * and `asset_number` — those change only through their own dedicated
 * actions (transfer/changeCondition/status-transition endpoints) so the
 * paired AssetHistory entry can never be skipped; see AssetService::update().
 */
class UpdateAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Asset $asset */
        $asset = $this->route('asset');

        return $this->user()?->can('update', $asset) ?? false;
    }

    public function rules(): array
    {
        /** @var Asset $asset */
        $asset = $this->route('asset');

        return [
            'category_id' => ['sometimes', 'required', Rule::exists('tenant.asset_categories', 'id')],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'brand' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255', Rule::unique('tenant.assets', 'serial_number')->ignore($asset)],
            'asset_tag' => ['nullable', 'string', 'max:64', Rule::unique('tenant.assets', 'asset_tag')->ignore($asset)],
            'purchase_date' => ['nullable', 'date'],
            'purchase_price' => ['nullable', 'numeric', 'min:0', 'max:99999999999999.99'],
            'current_value' => ['nullable', 'numeric', 'min:0', 'max:99999999999999.99'],
            'supplier_id' => ['nullable', Rule::exists('tenant.suppliers', 'id')],
            'warranty_start_date' => ['nullable', 'date'],
            'warranty_end_date' => ['nullable', 'date', 'after_or_equal:warranty_start_date'],
            'warranty_provider' => ['nullable', 'string', 'max:255'],
            'warranty_number' => ['nullable', 'string', 'max:100'],
            'hostname' => ['nullable', 'string', 'max:100'],
            'mac_address' => ['nullable', 'string', 'max:32'],
            'ip_address' => ['nullable', 'ip'],
            'specs' => ['sometimes', 'array'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
