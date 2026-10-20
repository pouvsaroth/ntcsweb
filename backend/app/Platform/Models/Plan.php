<?php

declare(strict_types=1);

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a school can be sold (docs/backoffice/stage-5-billing.md, 5a): a price
 * per period, a student limit and the modules it includes.
 */
class Plan extends Model
{
    public const LEGACY = 'legacy';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const CURRENCIES = ['USD', 'KHR'];

    protected $table = 'bo_plans';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'period_months' => 'integer',
            'max_students' => 'integer',
            'modules' => 'array',
            'menus' => 'array',
            'show_on_website' => 'boolean',
            'recommended' => 'boolean',
            'website_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // A plan made without a system sells the school system (as every plan did before systems).
        static::creating(function (Plan $plan) {
            $plan->product_id ??= Product::query()->where('code', Product::SCHOOL)->value('id');
        });
        // Editing a plan's modules must reach this request's permission checks too.
        static::saved(fn () => app(\App\Platform\Billing\SchoolModules::class)->forget());
    }

    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    /** The system this plan sells (docs/backoffice/stage-9-products.md). */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(SchoolSubscription::class);
    }

    public static function legacy(): self
    {
        return self::query()->where('code', self::LEGACY)->firstOrFail();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
