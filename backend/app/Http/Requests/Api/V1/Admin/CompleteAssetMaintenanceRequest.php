<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\AssetMaintenance;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteAssetMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var AssetMaintenance $maintenance */
        $maintenance = $this->route('asset_maintenance');

        return $this->user()?->can('update', $maintenance) ?? false;
    }

    public function rules(): array
    {
        return [
            'completed_date' => ['nullable', 'date'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'required', Rule::in([Tenant::CURRENCY_USD, Tenant::CURRENCY_KHR])],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
