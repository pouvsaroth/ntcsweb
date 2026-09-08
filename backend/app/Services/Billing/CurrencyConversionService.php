<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\CurrencyRate;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Converts a mixed-currency amount into the tenant's `default_currency` for
 * dashboard totals — see AccountingReportService and BillingDashboardController,
 * the only two callers. Each amount is converted using the rate in effect on
 * *that amount's own date*, not today's rate, so a historical total stays
 * accurate as the KHR rate drifts over time.
 */
final class CurrencyConversionService
{
    /**
     * The KHR-per-USD rate in effect on `$date` — the most recent
     * `currency_rates` row on or before it. Falls back to the earliest rate
     * on file if `$date` predates every rate entered so far. Returns null
     * only when the tenant has never entered a single rate — callers treat
     * that as "can't convert" and leave the amount as-is, same as before
     * this feature existed.
     */
    public function rateForDate(Carbon|string $date): ?float
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        $rate = CurrencyRate::query()
            ->whereDate('effective_date', '<=', $date)
            ->orderByDesc('effective_date')
            ->first();

        $rate ??= CurrencyRate::query()
            ->orderBy('effective_date')
            ->first();

        return $rate !== null ? (float) $rate->khr_per_usd : null;
    }

    /**
     * `$khrPerUsd` is null exactly when {@see rateForDate()} found nothing —
     * pass the amount through unconverted rather than guess a rate.
     */
    public function convert(float $amount, string $from, string $to, ?float $khrPerUsd): float
    {
        if ($from === $to || $khrPerUsd === null || $khrPerUsd <= 0.0) {
            return $amount;
        }

        if ($from === Tenant::CURRENCY_USD && $to === Tenant::CURRENCY_KHR) {
            return $amount * $khrPerUsd;
        }

        if ($from === Tenant::CURRENCY_KHR && $to === Tenant::CURRENCY_USD) {
            return $amount / $khrPerUsd;
        }

        return $amount;
    }

    /**
     * Folds a small grouped-by-(currency, date) result set into one total in
     * the tenant's default currency. The row count here is bounded by
     * `distinct dates in range × 2 currencies`, never by transaction volume —
     * see the callers' own docblocks for why that bound matters.
     *
     * @param  iterable<object{currency: string, tx_date: string, total: float|string}>  $rows
     */
    public function sumConverted(iterable $rows, Tenant $tenant): float
    {
        $total = 0.0;

        foreach ($rows as $row) {
            $rate = $this->rateForDate($row->tx_date);
            $total += $this->convert((float) $row->total, $row->currency, $tenant->default_currency, $rate);
        }

        return round($total, 2);
    }
}
