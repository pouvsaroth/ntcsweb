<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Audit\AuditAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned. One payroll (HRM > Payroll) for a pay period — a month, or
 * one half of it for a 15-day payroll. Draft (worked out, can be
 * recalculated and adjusted) → pending (waiting for approval, through an
 * Approval Flow if the school set one) → approved → paid (net pay posted
 * to Accounting, loan installments taken); or rejected (back to fix and
 * send again) / cancelled. See PayrollRunService.
 */
#[Fillable([
    'reference', 'month', 'period', 'period_start', 'period_end', 'pay_date', 'status', 'khr_per_usd', 'note',
    'created_by', 'calculated_at', 'requested_by', 'submitted_at', 'decided_by', 'decided_at', 'decision_reason',
    'paid_by', 'paid_at', 'expense_id',
])]
class PayrollRun extends Model
{
    use Auditable;

    public const PERIOD_MONTH = 'month';

    public const PERIOD_FIRST_HALF = 'first_half';

    public const PERIOD_SECOND_HALF = 'second_half';

    public const PERIODS = [self::PERIOD_MONTH, self::PERIOD_FIRST_HALF, self::PERIOD_SECOND_HALF];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    /** Statuses that still hold their pay period — a new run can't overlap one. */
    public const STATUSES_HOLDING = [self::STATUS_DRAFT, self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_PAID, self::STATUS_REJECTED];

    protected $connection = 'tenant';

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'pay_date' => 'date',
            'khr_per_usd' => 'float',
            'calculated_at' => 'datetime',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /** A rejected run can be fixed and sent again, so it's still editable. */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REJECTED], true);
    }

    public function auditModule(): string
    {
        return 'Payroll';
    }

    protected function auditActionForDirty(array $dirty): string
    {
        return array_key_exists('status', $dirty) ? AuditAction::STATUS_CHANGE : AuditAction::UPDATE;
    }
}
