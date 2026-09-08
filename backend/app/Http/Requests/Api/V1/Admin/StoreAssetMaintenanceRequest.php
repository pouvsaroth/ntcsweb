<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\AssetMaintenance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AssetMaintenance::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'maintenance_type' => ['required', 'string', 'max:255'],
            'scheduled_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:2000'],
            'repair_shop_id' => ['nullable', Rule::exists('tenant.repair_shops', 'id')],
            'recurrence_interval_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
