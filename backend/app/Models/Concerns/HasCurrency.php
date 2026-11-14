<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * A record whose money is in its own currency (USD or KHR). A new record
 * saved without one gets the school's currency; amounts are never assumed
 * to be dollars.
 *
 * @mixin Model
 */
trait HasCurrency
{
    public static function bootHasCurrency(): void
    {
        static::creating(function (Model $model): void {
            if (empty($model->getAttribute('currency'))) {
                $model->setAttribute('currency', app(TenantContext::class)->get()?->default_currency ?: Tenant::CURRENCY_USD);
            }
        });
    }
}
