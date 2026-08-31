<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Academic\AttendanceStatus;
use Database\Factories\AttendanceRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One student's attendance for one class on one calendar date. Does NOT use
 * the Auditable trait: attendance is taken in a batch (a whole class roster
 * at once), so AttendanceService writes a single summarizing audit entry per
 * batch ("Recorded attendance for X on Y: n present, n absent...") instead of
 * one generic row per student — the same reasoning as Invoice/Payment.
 *
 * @property int $tenant_id
 * @property int $enrollment_id
 * @property int $class_id
 * @property int $student_id
 * @property string $status
 */
#[Fillable(['enrollment_id', 'class_id', 'student_id', 'date', 'status', 'remarks', 'recorded_by', 'recorded_at'])]
class AttendanceRecord extends Model
{
    /** @use HasFactory<AttendanceRecordFactory> */
    use BelongsToTenant, HasFactory;

    protected $attributes = [
        'status' => AttendanceStatus::PRESENT,
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'recorded_at' => 'datetime',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeOnDate(Builder $query, string $date): void
    {
        $query->whereDate('date', $date);
    }

    public function auditDisplayName(): string
    {
        return "{$this->student?->fullName()} on {$this->date?->toDateString()}";
    }
}
