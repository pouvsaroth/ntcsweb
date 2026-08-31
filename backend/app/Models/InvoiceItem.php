<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\InvoiceItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One billed line on an Invoice — see that migration's docblock for why
 * `unit_price`/`discount` are a snapshot, not a live read of Product::$price.
 *
 * @property int $tenant_id
 * @property int $invoice_id
 * @property int $product_id
 * @property string $unit_price
 */
#[Fillable([
    'invoice_id', 'product_id', 'product_variant_id', 'description',
    'quantity', 'unit_price', 'discount', 'subtotal', 'total',
    'reference_type', 'reference_id',
])]
class InvoiceItem extends Model
{
    /** @use HasFactory<InvoiceItemFactory> */
    use BelongsToTenant, HasFactory;

    protected $attributes = [
        'quantity' => 1,
        'discount' => 0,
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'discount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** Optionally, the business record this charge came from — e.g. an Enrollment for a course-fee item. */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
