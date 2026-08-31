<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\InvoiceItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InvoiceItem
 */
class InvoiceItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => $this->whenLoaded('product', fn () => $this->product->name),
            'product_variant_id' => $this->product_variant_id,
            'variant_name' => $this->whenLoaded('variant', fn () => $this->variant?->name),
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_price' => (float) $this->unit_price,
            'discount' => (float) $this->discount,
            'subtotal' => (float) $this->subtotal,
            'total' => (float) $this->total,
        ];
    }
}
