<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Models\WebsiteVisit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/**
 * The public site's visitor counter — see WebsiteVisit's own docblock for
 * the storage shape. `record()` is called once per browser per calendar day
 * (deduped client-side, see PublicLayout.vue/site.ts's `pingVisit()`), so
 * `visits` on a given day is a "daily unique-ish visitor" count, not a raw
 * hit counter.
 */
final class WebsiteVisitStats
{
    /**
     * Increments today's row, creating it first if this is the day's first
     * visit. Wrapped in a transaction with a row lock rather than a bare
     * upsert-with-increment, since that syntax isn't portable across the
     * database drivers this app runs on (see docker-compose.yml/.prod.yml).
     */
    public static function record(): void
    {
        DB::connection('tenant')->transaction(function () {
            $today = Carbon::today()->toDateString();

            $row = WebsiteVisit::query()->where('visit_date', $today)->lockForUpdate()->first();

            if ($row !== null) {
                $row->increment('visits');

                return;
            }

            WebsiteVisit::query()->create(['visit_date' => $today, 'visits' => 1]);
        });
    }

    /**
     * @return array{today: int, yesterday: int, weekly: int, monthly: int, yearly: int}
     */
    public static function summary(): array
    {
        $today = Carbon::today();

        return [
            'today' => self::sumBetween($today, $today),
            'yesterday' => self::sumBetween($today->copy()->subDay(), $today->copy()->subDay()),
            'weekly' => self::sumBetween($today->copy()->startOfWeek(), $today),
            'monthly' => self::sumBetween($today->copy()->startOfMonth(), $today),
            'yearly' => self::sumBetween($today->copy()->startOfYear(), $today),
        ];
    }

    private static function sumBetween(Carbon $from, Carbon $to): int
    {
        return (int) WebsiteVisit::query()
            ->whereBetween('visit_date', [$from->toDateString(), $to->toDateString()])
            ->sum('visits');
    }
}
