<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A product's variant — a T-Shirt's "Large", a Book's "Hardcover" — never
 * required (see Product's docblock; most products have none at all).
 *
 * @property int $tenant_id
 * @property int $product_id
 * @property string $name
 */
#[Fillable(['product_id', 'name', 'price_override', 'is_active'])]
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use BelongsToTenant, HasFactory;

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'price_override' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** The price to bill — the variant's own override if it has one, otherwise the parent product's. */
    public function effectivePrice(): string
    {
        return $this->price_override ?? $this->product->price;
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
