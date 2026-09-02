<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\LookupCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLookupCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var LookupCategory $lookupCategory */
        $lookupCategory = $this->route('lookup_category');

        return $this->user()?->can('update', $lookupCategory) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();
        /** @var LookupCategory $lookupCategory */
        $lookupCategory = $this->route('lookup_category');

        return [
            'code' => ['sometimes', 'required', 'string', 'max:50', 'regex:/^[A-Z0-9_]+$/', Rule::unique('lookup_categories')->where('tenant_id', $tenantId)->ignore($lookupCategory)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
