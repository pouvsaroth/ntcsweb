<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Product;
use App\Support\Billing\ProductType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Product $product */
        $product = $this->route('product');

        return $this->user()?->can('update', $product) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();
        $product = $this->route('product');

        return [
            'code' => ['sometimes', 'required', 'string', 'max:32', Rule::unique('products')->where('tenant_id', $tenantId)->ignore($product)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'type' => ['sometimes', Rule::in(ProductType::all())],

            // Never retroactive — see InvoiceItem's docblock: changing this
            // only affects invoices created after the change, since every
            // item already snapshots the price it was billed at.
            'price' => ['sometimes', 'required', 'numeric', 'min:0', 'max:999999.99'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
