<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\AssetMaintenance;
use Illuminate\Foundation\Http\FormRequest;

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
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
