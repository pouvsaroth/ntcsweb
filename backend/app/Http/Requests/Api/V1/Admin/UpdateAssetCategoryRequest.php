<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\AssetCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssetCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var AssetCategory $category */
        $category = $this->route('asset_category');

        return $this->user()?->can('update', $category) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();
        /** @var AssetCategory $category */
        $category = $this->route('asset_category');

        return [
            'code' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('asset_categories')->where('tenant_id', $tenantId)->ignore($category)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'parent_id' => ['nullable', Rule::exists('asset_categories', 'id')->where('tenant_id', $tenantId), 'not_in:'.$category->getKey()],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
