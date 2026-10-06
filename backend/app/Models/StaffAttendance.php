<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. One staff member's attendance on one working date (HRM >
 * Attendance & Time) — see the migration's docblock. The minutes and
 * `status` are always recalculated from the check-in/out and the shift by
 * StaffAttendanceService, never typed in.
 *
 * @property string $status
 */
#[Fillable([
    'staff_id', 'date', 'shift_id', 'scheduled_start', 'scheduled_end', 'check_in_at', 'check_out_at',
    'check_in_source', 'check_out_source', 'check_in_latitude', 'check_in_longitude', 'check_out_latitude', 'check_out_longitude',
    'status', 'late_minutes', 'early_leave_minutes', 'worked_minutes', 'overtime_minutes', 'note', 'updated_by',
])]
class StaffAttendance extends Model
{
    use Auditable;

    // Days with a check-in.
    public const STATUS_PRESENT = 'present';

    public const STATUS_LATE = 'late';

    public const STATUS_EARLY_LEAVE = 'early_leave';

    /** Checked in, not (yet) out. */
    public const STATUS_INCOMPLETE = 'incomplete';

    // Days without one — worked out, not stored (see StaffAttendanceService::sheet()).
    public const STATUS_ABSENT = 'absent';

    public const STATUS_HOLIDAY = 'holiday';

    public const STATUS_LEAVE = 'leave';

    public const STATUS_OFF = 'off';

    public const SOURCE_APP = 'app';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_IMPORT = 'import';

    /** An approved Attendance correction. */
    public const SOURCE_CORRECTION = 'correction';

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'staff_id' => 'integer',
            'shift_id' => 'integer',
            'date' => 'date',
            'scheduled_start' => 'datetime',
            'scheduled_end' => 'datetime',
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'late_minutes' => 'integer',
            'early_leave_minutes' => 'integer',
            'worked_minutes' => 'integer',
            'overtime_minutes' => 'integer',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function auditModule(): string
    {
        return 'Attendance';
    }

    public function auditDisplayName(): string
    {
        return "{$this->staff?->fullName()}: {$this->date?->format('d-m-Y')}";
    }
}
