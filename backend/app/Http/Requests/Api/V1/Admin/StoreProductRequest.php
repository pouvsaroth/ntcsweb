<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Product;
use App\Models\Tenant;
use App\Support\Billing\ProductType;
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
        return [
            'code' => ['required', 'string', 'max:32', Rule::unique('tenant.products', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'type' => ['sometimes', Rule::in(ProductType::all())],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'currency' => ['sometimes', 'required', Rule::in([Tenant::CURRENCY_USD, Tenant::CURRENCY_KHR])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
