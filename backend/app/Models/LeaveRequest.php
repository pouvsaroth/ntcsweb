<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Audit\AuditAction;
use Database\Factories\LeaveRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A student's or staff member's own self-submitted leave/permission
 * request — owned by exactly one of `student_id`/`staff_id`, never both
 * (see the 2026_09_16_020000 migration's docblock). Deliberately a separate
 * entity from AttendanceRecord rather than a new status on it. Approving a
 * student-owned one (see LeaveRequestService::approve()) writes
 * AttendanceRecord rows (status EXCUSED) for every date in the range that
 * one of the student's active enrollments' classes actually meets, via the
 * existing AttendanceService — never touched directly here. A staff-owned
 * one has no attendance to write; approving it is just a status change.
 *
 * @property int|null $student_id
 * @property int|null $staff_id
 * @property string $status
 */
#[Fillable(['student_id', 'staff_id', 'from_date', 'to_date', 'from_time', 'to_time', 'reason', 'status', 'decision_reason', 'decided_by', 'decided_at'])]
class LeaveRequest extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    /** @use HasFactory<LeaveRequestFactory> */
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $connection = 'tenant';

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    /** Whoever actually owns this request — a student, a staff member, but always exactly one. */
    public function requesterName(): ?string
    {
        return $this->student?->fullName() ?? $this->staff?->fullName();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(LeaveRequestAttachment::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', self::STATUS_PENDING);
    }

    public function auditModule(): string
    {
        return 'Attendance';
    }

    public function auditDisplayName(): string
    {
        return "{$this->requesterName()}: {$this->from_date?->format('d-m-Y')}–{$this->to_date?->format('d-m-Y')}";
    }

    protected function auditActionForDirty(array $dirty): string
    {
        return array_key_exists('status', $dirty) ? AuditAction::STATUS_CHANGE : AuditAction::UPDATE;
    }

    protected function auditDescriptionForChange(string $action, array $old, array $new): ?string
    {
        if ($action === AuditAction::STATUS_CHANGE) {
            return "Changed leave request for {$this->requesterName()} status from {$old['status']} to {$new['status']}";
        }

        return null;
    }
}
