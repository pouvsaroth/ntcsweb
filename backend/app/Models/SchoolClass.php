<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SchoolClassFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Tenant-owned. A scheduled teaching group — a section students enroll into,
 * taught in one room by one or more staff (e.g. "Excel Basics — Evening
 * Batch 12"), each tagged as a main teacher or an assistant (see
 * ROLE_TEACHER/ROLE_ASSISTANT and the `teachers()`/`assistantTeachers()`
 * relations below) — both repeatable, so co-teaching is normal, not an edge
 * case.
 *
 * Named `SchoolClass`, not `Class`: `class` is a reserved word in PHP and
 * cannot name a class at all. The table is still plainly `classes`.
 *
 * Optionally linked to an Academic Program — a class is purely a
 * schedule/room/teacher grouping, so the link is nullable and never gates
 * which course packages can be enrolled into it (see EnrollmentService).
 *
 * @property string $name
 * @property string $status
 */
#[Fillable(['classroom_id', 'academic_program_id', 'name', 'code', 'capacity', 'start_date', 'end_date', 'status'])]
class SchoolClass extends Model
{
    use HasFactory, SoftDeletes;

    protected $connection = 'tenant';

    /** @use HasFactory<SchoolClassFactory> */
    protected $table = 'classes';

    public const STATUS_UPCOMING = 'upcoming';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const ROLE_TEACHER = 'teacher';

    public const ROLE_ASSISTANT = 'assistant';

    /** PHP-level mirror of the column's DB default — see Building for why. */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(Staff::class, 'class_teachers', 'class_id', 'staff_id')
            ->withPivotValue('role', self::ROLE_TEACHER)
            ->withTimestamps();
    }

    public function assistantTeachers(): BelongsToMany
    {
        return $this->belongsToMany(Staff::class, 'class_teachers', 'class_id', 'staff_id')
            ->withPivotValue('role', self::ROLE_ASSISTANT)
            ->withTimestamps();
    }

    /** Every assigned staff member regardless of role (teacher or assistant). */
    public function teachingStaff(): BelongsToMany
    {
        return $this->belongsToMany(Staff::class, 'class_teachers', 'class_id', 'staff_id')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function academicProgram(): BelongsTo
    {
        return $this->belongsTo(AcademicProgram::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ClassSchedule::class, 'class_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'class_id');
    }

    /**
     * How many minutes one attendance day in this class is worth on a given
     * ISO weekday (1 = Monday … 7 = Sunday): that weekday's scheduled
     * session(s). Attendance taken on a day the class doesn't normally meet
     * (e.g. a Sunday for a Mon–Fri class) still counts — as the class's usual
     * session length, the most common daily total (ties: the longer one) —
     * rather than silently counting 0 hours. 0 only for a class with no
     * schedule at all. Uses the loaded `schedules` relation when present.
     */
    public function minutesOn(int $isoWeekday): int
    {
        $byWeekday = $this->schedules
            ->groupBy('day_of_week')
            ->map(fn ($rows) => (int) $rows->sum(fn (ClassSchedule $schedule) => abs(Carbon::parse($schedule->end_time)->diffInMinutes(Carbon::parse($schedule->start_time)))));

        if ($byWeekday->has($isoWeekday)) {
            return $byWeekday->get($isoWeekday);
        }

        return (int) ($byWeekday->countBy()->sortKeysDesc()->sortDesc()->keys()->first() ?? 0);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }
}
