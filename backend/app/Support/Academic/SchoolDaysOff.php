<?php

declare(strict_types=1);

namespace App\Support\Academic;

use App\Models\Holiday;
use App\Models\SchoolCalendarEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Days with no classes, for student attendance: HRM Holidays plus School
 * Calendar "closing" days. On these days the "attendance not taken"
 * reminder is not sent and the day is skipped by the previous-class-day lock.
 */
final class SchoolDaysOff
{
    /**
     * Days off overlapping [$from, $to] (Y-m-d).
     *
     * @return Collection<int, array{start: string, end: string, name: string}>
     */
    public static function between(string $from, string $to): Collection
    {
        $holidays = Holiday::query()
            ->whereDate('end_date', '>=', $from)->whereDate('start_date', '<=', $to)
            ->get()
            ->map(fn (Holiday $h) => ['start' => $h->start_date->toDateString(), 'end' => $h->end_date->toDateString(), 'name' => $h->name]);

        $closings = SchoolCalendarEvent::query()
            ->where('type', SchoolCalendarEvent::TYPE_CLOSING)
            ->whereDate('end_date', '>=', $from)->whereDate('start_date', '<=', $to)
            ->get()
            ->map(fn (SchoolCalendarEvent $e) => [
                'start' => $e->start_date->toDateString(),
                'end' => $e->end_date->toDateString(),
                'name' => $e->title,
            ]);

        return $holidays->concat($closings)->values();
    }

    /** The name of the day off covering $date, or null on a normal day. */
    public static function on(CarbonInterface|string $date): ?string
    {
        $day = Carbon::parse($date)->toDateString();

        return self::between($day, $day)->first()['name'] ?? null;
    }

    /** @param  Collection<int, array{start: string, end: string, name: string}>  $daysOff */
    public static function covers(Collection $daysOff, string $ymd): bool
    {
        return $daysOff->contains(fn (array $d) => $d['start'] <= $ymd && $d['end'] >= $ymd);
    }
}
