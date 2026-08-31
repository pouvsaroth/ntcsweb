<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Product;
use App\Support\Billing\ProductType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Product::class) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'code' => ['required', 'string', 'max:32', Rule::unique('products')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'type' => ['sometimes', Rule::in(ProductType::all())],
            'price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
