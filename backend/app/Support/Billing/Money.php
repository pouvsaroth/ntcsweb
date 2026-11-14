<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Tenant;

/**
 * One amount as text, in the currency it is really in: "30,000 ៛" or
 * "$30.00". Never assume dollars — a KHR amount shown as "$30000.00" is the
 * bug this exists to prevent. A null currency is the caller's job to fill
 * in (the record's own, else the school's).
 */
final class Money
{
    public static function format(float|int|string|null $amount, ?string $currency): string
    {
        $value = (float) ($amount ?? 0);
        $sign = $value < 0 ? '-' : '';

        return $currency === Tenant::CURRENCY_KHR
            ? $sign.number_format(abs(round($value)), 0).' ៛'
            : $sign.'$'.number_format(abs($value), 2);
    }
}
