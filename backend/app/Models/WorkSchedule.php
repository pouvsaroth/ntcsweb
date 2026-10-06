<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Carbon\CarbonInterface;
use Database\Factories\WorkScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned. A weekly working pattern (HRM > Attendance & Time > Work
 * schedule): the shift on each weekday, none meaning a day off. Staff
 * follow the one set on them, else the school's default (`is_default`,
 * only ever one — see WorkScheduleController).
 */
#[Fillable(['name', 'description', 'monday_shift_id', 'tuesday_shift_id', 'wednesday_shift_id', 'thursday_shift_id', 'friday_shift_id', 'saturday_shift_id', 'sunday_shift_id', 'is_default'])]
class WorkSchedule extends Model
{
    /** @use HasFactory<WorkScheduleFactory> */
    use Auditable, HasFactory;

    /** Monday first — the column prefixes. */
    public const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    protected $connection = 'tenant';

    protected $attributes = [
        'is_default' => false,
    ];

    protected function casts(): array
    {
        return [
            'monday_shift_id' => 'integer',
            'tuesday_shift_id' => 'integer',
            'wednesday_shift_id' => 'integer',
            'thursday_shift_id' => 'integer',
            'friday_shift_id' => 'integer',
            'saturday_shift_id' => 'integer',
            'sunday_shift_id' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    public function mondayShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'monday_shift_id');
    }

    public function tuesdayShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'tuesday_shift_id');
    }

    public function wednesdayShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'wednesday_shift_id');
    }

    public function thursdayShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'thursday_shift_id');
    }

    public function fridayShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'friday_shift_id');
    }

    public function saturdayShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'saturday_shift_id');
    }

    public function sundayShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'sunday_shift_id');
    }

    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class);
    }

    /** The shift id on that date's weekday, or null for a day off. */
    public function shiftIdOn(CarbonInterface $date): ?int
    {
        $day = self::DAYS[$date->dayOfWeekIso - 1];

        return $this->{"{$day}_shift_id"};
    }

    /** The schedule a staff member follows: their own, else the default. */
    public static function forStaff(Staff $staff): ?self
    {
        return ($staff->work_schedule_id !== null ? self::query()->find($staff->work_schedule_id) : null)
            ?? self::query()->where('is_default', true)->first();
    }

    public function auditModule(): string
    {
        return 'Attendance';
    }
}
