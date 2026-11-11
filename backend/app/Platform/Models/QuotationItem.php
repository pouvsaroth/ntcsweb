<?php

declare(strict_types=1);

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Billing > Quotation items: one console menu, submenu or tab a quotation can
 * pick, with its default price in USD and KHR.
 */
class QuotationItem extends Model
{
    public const LEVELS = ['menu', 'submenu', 'tab'];

    protected $table = 'bo_quotation_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price_usd' => 'decimal:2',
            'price_khr' => 'decimal:0',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }
}
