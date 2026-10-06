<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Audit\AuditAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. A request to fix one day's check-in/out (HRM > Attendance &
 * Time > Attendance correction), decided in E-Approvals. The times are the
 * school's local "HH:MM"; an empty one leaves that side as recorded.
 *
 * @property string $status
 */
#[Fillable(['staff_id', 'date', 'check_in', 'check_out', 'reason', 'status', 'requested_by', 'decision_reason', 'decided_by', 'decided_at'])]
class AttendanceCorrection extends Model
{
    use Auditable;

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
            'staff_id' => 'integer',
            'date' => 'date',
            'requested_by' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    /** "AC-000005". */
    public function reference(): string
    {
        return 'AC-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function auditModule(): string
    {
        return 'Attendance';
    }

    public function auditDisplayName(): string
    {
        return "{$this->reference()}: {$this->staff?->fullName()}";
    }

    protected function auditActionForDirty(array $dirty): string
    {
        return array_key_exists('status', $dirty) ? AuditAction::STATUS_CHANGE : AuditAction::UPDATE;
    }
}
