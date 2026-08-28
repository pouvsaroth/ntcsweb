<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ClassScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. The "study day" / "study time" of a class — one row per
 * weekly meeting slot. A class that meets Monday, Wednesday, and Friday has
 * three of these, not one row trying to hold multiple days.
 *
 * `tenant_id` is stored directly (not resolved by joining through `classes`)
 * so BelongsToTenant's scope can filter this table with no join — the same
 * pattern audit_logs and tenant_domains already use.
 *
 * @property int $tenant_id
 * @property int $class_id
 * @property int $day_of_week ISO-8601: 1 = Monday ... 7 = Sunday
 * @property string $start_time
 * @property string $end_time
 */
#[Fillable(['class_id', 'day_of_week', 'start_time', 'end_time'])]
class ClassSchedule extends Model
{
    use BelongsToTenant, HasFactory;

    /** @use HasFactory<ClassScheduleFactory> */
    public const MONDAY = 1;

    public const TUESDAY = 2;

    public const WEDNESDAY = 3;

    public const THURSDAY = 4;

    public const FRIDAY = 5;

    public const SATURDAY = 6;

    public const SUNDAY = 7;

    /** @var array<int, string> */
    public const DAY_NAMES = [
        self::MONDAY => 'Monday',
        self::TUESDAY => 'Tuesday',
        self::WEDNESDAY => 'Wednesday',
        self::THURSDAY => 'Thursday',
        self::FRIDAY => 'Friday',
        self::SATURDAY => 'Saturday',
        self::SUNDAY => 'Sunday',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
        ];
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function dayName(): string
    {
        return self::DAY_NAMES[$this->day_of_week] ?? 'Unknown';
    }
}
