<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\AssetRepair;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendAssetToRepairRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AssetRepair::class) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'issue_id' => ['nullable', Rule::exists('asset_issues', 'id')->where('tenant_id', $tenantId)],
            'repair_shop_id' => ['nullable', Rule::exists('repair_shops', 'id')->where('tenant_id', $tenantId)],
            'sent_date' => ['nullable', 'date'],
            'expected_return_date' => ['nullable', 'date', 'after_or_equal:sent_date'],
            'problem_description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
