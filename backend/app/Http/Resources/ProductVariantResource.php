<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductVariant
 */
class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'name' => $this->name,
            // Not effective_price here: that needs the parent Product's own
            // price (see ProductVariant::effectivePrice()), which isn't
            // safe to lazy-load from inside a resource under
            // Model::shouldBeStrict(). A caller that already has the
            // product (this is always nested under ProductResource) can
            // compose `price_override ?? product.price` itself.
            'price_override' => $this->price_override !== null ? (float) $this->price_override : null,
            'is_active' => $this->is_active,
        ];
    }
}
