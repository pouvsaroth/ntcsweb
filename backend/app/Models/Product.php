<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Billing\ProductType;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tenant-owned. Anything a student can be billed for — a course fee, a
 * book, a T-shirt, a certificate, or anything else a school decides to
 * sell. This is the one catalog every InvoiceItem points at; there is no
 * per-product-type table (see the invoice_items migration's docblock).
 *
 * @property int $tenant_id
 * @property string $code
 * @property string $name
 * @property string $type
 * @property string $price
 * @property bool $is_active
 */
#[Fillable(['code', 'name', 'description', 'type', 'price', 'is_active', 'revenue_account_id'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    protected $attributes = [
        'type' => ProductType::OTHER,
        'price' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /** Optional override of which Revenue account a sale of this product posts to — see RevenueAccountResolver. */
    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'revenue_account_id');
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function auditModule(): string
    {
        return 'Products';
    }
}
