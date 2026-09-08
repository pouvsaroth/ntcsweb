<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\AssetCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AssetCategory::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', Rule::unique('tenant.asset_categories', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'parent_id' => ['nullable', Rule::exists('tenant.asset_categories', 'id')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
