<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\StaffLoanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned. A loan or salary advance to a staff member (HRM > Payroll >
 * Loan/advance), in their salary currency. Paid back by `installment_amount`
 * from every payroll covering `first_deduction_on` or later (the last one
 * takes only what's left), or in cash. Settled once repayments reach the
 * amount.
 */
#[Fillable(['staff_id', 'type', 'amount', 'currency', 'issued_on', 'installment_amount', 'first_deduction_on', 'status', 'reason', 'created_by'])]
class StaffLoan extends Model
{
    /** @use HasFactory<StaffLoanFactory> */
    use Auditable, HasFactory;

    public const TYPE_LOAN = 'loan';

    public const TYPE_ADVANCE = 'advance';

    public const TYPES = [self::TYPE_LOAN, self::TYPE_ADVANCE];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_CANCELLED = 'cancelled';

    protected $connection = 'tenant';

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'installment_amount' => 'decimal:2',
            'issued_on' => 'date',
            'first_deduction_on' => 'date',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(StaffLoanRepayment::class);
    }

    public function repaid(): float
    {
        // withSum('repayments', 'amount') when listed; a query otherwise.
        $sum = $this->hasAttribute('repayments_sum_amount') ? $this->getAttribute('repayments_sum_amount') : $this->repayments()->sum('amount');

        return round((float) $sum, 2);
    }

    public function balance(): float
    {
        return max(round((float) $this->amount - $this->repaid(), 2), 0);
    }

    /** Settles it once paid back, or reopens it if a repayment was removed. */
    public function refreshStatus(): void
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return;
        }

        $this->unsetRelation('repayments');
        $this->offsetUnset('repayments_sum_amount');
        $status = $this->balance() <= 0 ? self::STATUS_SETTLED : self::STATUS_ACTIVE;

        if ($status !== $this->status) {
            $this->update(['status' => $status]);
        }
    }

    public function auditModule(): string
    {
        return 'Payroll';
    }
}
