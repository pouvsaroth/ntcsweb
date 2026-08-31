<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ProductVariant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ProductVariant $variant */
        $variant = $this->route('variant');

        return $this->user()?->can('update', $variant->product) ?? false;
    }

    public function rules(): array
    {
        /** @var ProductVariant $variant */
        $variant = $this->route('variant');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:64', Rule::unique('product_variants')->where('product_id', $variant->product_id)->ignore($variant)],
            'price_override' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
