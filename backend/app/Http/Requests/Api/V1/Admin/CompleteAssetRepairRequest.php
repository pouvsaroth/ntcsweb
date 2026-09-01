<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\AssetRepair;
use App\Support\Assets\AssetCondition;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** `total_cost` is never accepted — always server-computed from the individual cost fields. */
class CompleteAssetRepairRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var AssetRepair $repair */
        $repair = $this->route('asset_repair');

        return $this->user()?->can('complete', $repair) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'expense_account_id' => ['required', Rule::exists('accounts', 'id')->where('tenant_id', $tenantId)],
            'repair_description' => ['nullable', 'string', 'max:2000'],
            'condition_after_repair' => ['nullable', Rule::in(AssetCondition::all())],
            'warranty_days' => ['nullable', 'integer', 'min:0'],
            'diagnosis_cost' => ['sometimes', 'numeric', 'min:0'],
            'parts_cost' => ['sometimes', 'numeric', 'min:0'],
            'labor_cost' => ['sometimes', 'numeric', 'min:0'],
            'transport_cost' => ['sometimes', 'numeric', 'min:0'],
            'other_cost' => ['sometimes', 'numeric', 'min:0'],
        ];
    }
}
