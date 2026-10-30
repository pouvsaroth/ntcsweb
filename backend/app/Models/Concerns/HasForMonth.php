<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The "For month" of an expense or income (Account > Expense / Income): the
 * month it belongs to, which may differ from the day the money moved. Kept as
 * that month's first day; set it from 'YYYY-MM' or any date. Left empty, it
 * takes the month of the row's own date (FOR_MONTH_FROM).
 *
 * @property Carbon|null $for_month
 */
trait HasForMonth
{
    public static function bootHasForMonth(): void
    {
        static::saving(function (self $model): void {
            $date = $model->getAttribute(static::FOR_MONTH_FROM);
            if ($model->getAttribute('for_month') === null && $date !== null) {
                $model->setAttribute('for_month', $date);
            }
        });
    }

    public function initializeHasForMonth(): void
    {
        $this->mergeCasts(['for_month' => 'date']);
    }

    /** Any date, or 'YYYY-MM', becomes the first day of its month. */
    public function setForMonthAttribute(string|CarbonInterface|null $value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['for_month'] = null;

            return;
        }
        $month = is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value) === 1 ? Carbon::createFromFormat('!Y-m', $value) : Carbon::parse($value);
        $this->attributes['for_month'] = $month->startOfMonth()->toDateString();
    }

    /** @param  Builder<static>  $query */
    public function scopeForMonth(Builder $query, string $month): Builder
    {
        return $query->whereDate('for_month', Carbon::createFromFormat('!Y-m', $month)->startOfMonth()->toDateString());
    }
}
