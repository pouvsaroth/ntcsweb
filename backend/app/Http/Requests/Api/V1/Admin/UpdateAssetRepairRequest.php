<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\AssetRepair;
use App\Models\Tenant;
use App\Support\Assets\RepairStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** `total_cost` is never accepted — AssetRepairService always recomputes it server-side from the individual cost fields. */
class UpdateAssetRepairRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var AssetRepair $repair */
        $repair = $this->route('asset_repair');

        return $this->user()?->can('update', $repair) ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(RepairStatus::all())],
            'diagnosis' => ['nullable', 'string', 'max:2000'],
            'repair_description' => ['nullable', 'string', 'max:2000'],
            'currency' => ['sometimes', 'required', Rule::in([Tenant::CURRENCY_USD, Tenant::CURRENCY_KHR])],
            'diagnosis_cost' => ['sometimes', 'numeric', 'min:0'],
            'parts_cost' => ['sometimes', 'numeric', 'min:0'],
            'labor_cost' => ['sometimes', 'numeric', 'min:0'],
            'transport_cost' => ['sometimes', 'numeric', 'min:0'],
            'other_cost' => ['sometimes', 'numeric', 'min:0'],
        ];
    }
}
