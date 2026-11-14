<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\CurrencyRate;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * One amount as text, in the currency it is really in: "30,000 ៛" or
 * "$30.00". Never assume dollars — a KHR amount shown as "$30000.00" is the
 * bug this exists to prevent. A null currency is the caller's job to fill
 * in (the record's own, else the school's).
 *
 * Documents and reports show every amount in both currencies (a USD column
 * and a KHR column): pair() gives both, converting the other side with the
 * Currency Rates rate in effect on the record's date (rateOn()).
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

    /**
     * The Currency Rates row in effect on `$date`: the latest one whose
     * effective date is on or before it (else the earliest on file), or null
     * when the school has never entered a rate.
     */
    public static function rateOn(Carbon|string|null $date): ?CurrencyRate
    {
        $date = $date === null ? now() : Carbon::parse($date);

        return CurrencyRate::query()->whereDate('effective_date', '<=', $date)->orderByDesc('effective_date')->first()
            ?? CurrencyRate::query()->orderBy('effective_date')->first();
    }

    /**
     * The amount in USD and in KHR. The side it is not in is converted with
     * `$khrPerUsd`; that side is null when there is no rate.
     *
     * @return array{USD: float|null, KHR: float|null}
     */
    public static function pair(float|int|string|null $amount, ?string $currency, ?float $khrPerUsd): array
    {
        $value = (float) ($amount ?? 0);
        $rate = $khrPerUsd !== null && $khrPerUsd > 0 ? $khrPerUsd : null;

        if ($currency === Tenant::CURRENCY_KHR) {
            return ['USD' => $rate !== null ? round($value / $rate, 2) : null, 'KHR' => round($value)];
        }

        return ['USD' => round($value, 2), 'KHR' => $rate !== null ? round($value * $rate) : null];
    }

    /**
     * The KHR-per-USD rate in effect on `$date`, remembered for the rest of
     * this request / queued job (a list converts many rows on few dates).
     */
    public static function khrPerUsdOn(Carbon|string|null $date): ?float
    {
        $key = $date === null ? now()->toDateString() : Carbon::parse($date)->toDateString();
        if (! app()->bound('money.rates')) {
            app()->scoped('money.rates', fn () => new \ArrayObject);
        }
        $cache = app('money.rates');
        if (! $cache->offsetExists($key)) {
            $row = self::rateOn($key);
            $cache[$key] = $row !== null ? (float) $row->khr_per_usd : null;
        }

        return $cache[$key];
    }

    /**
     * One record's amount in USD and in KHR, converted at the rate on its
     * own date. Null amount stays null on both sides.
     *
     * @return array{USD: float|null, KHR: float|null}
     */
    public static function pairOn(float|int|string|null $amount, ?string $currency, Carbon|string|null $date): array
    {
        if ($amount === null) {
            return ['USD' => null, 'KHR' => null];
        }

        return self::pair($amount, $currency, self::khrPerUsdOn($date));
    }

    /** Both sides as text, "—" where there is no rate. @return array{USD: string, KHR: string} */
    public static function pairText(float|int|string|null $amount, ?string $currency, ?float $khrPerUsd): array
    {
        $pair = self::pair($amount, $currency, $khrPerUsd);

        return [
            'USD' => $pair['USD'] === null ? '—' : self::format($pair['USD'], Tenant::CURRENCY_USD),
            'KHR' => $pair['KHR'] === null ? '—' : self::format($pair['KHR'], Tenant::CURRENCY_KHR),
        ];
    }
}
