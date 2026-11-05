<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\BorrowingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned. Accounting > Borrowings > Loans: money borrowed from a
 * lender, for one "Borrowed for" (Newtech, Flexi, Family...). Paid back
 * monthly over `term_months` from `first_payment_on` (see BorrowingSchedule).
 */
#[Fillable([
    'lender_id', 'expense_entity_id', 'reference', 'amount', 'currency', 'received_on', 'cash_account_id',
    'interest_type', 'interest_rate', 'interest_period', 'term_months', 'first_payment_on', 'purpose',
    'contract_path', 'contract_name', 'status', 'created_by',
])]
class Borrowing extends Model
{
    /** @use HasFactory<BorrowingFactory> */
    use Auditable, HasFactory;

    public const INTEREST_TYPES = ['none', 'flat', 'reducing'];

    public const INTEREST_PERIODS = ['month', 'year'];

    public const STATUSES = ['active', 'paid_off', 'cancelled'];

    protected $connection = 'tenant';

    protected $attributes = [
        'interest_type' => 'none',
        'interest_rate' => 0,
        'interest_period' => 'month',
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'interest_rate' => 'decimal:4',
            'received_on' => 'date',
            'first_payment_on' => 'date',
            'term_months' => 'integer',
        ];
    }

    public function lender(): BelongsTo
    {
        return $this->belongsTo(Lender::class);
    }

    public function borrowedFor(): BelongsTo
    {
        return $this->belongsTo(ExpenseEntity::class, 'expense_entity_id');
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'cash_account_id');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(BorrowingRepayment::class);
    }

    public function auditModule(): string
    {
        return 'Accounting';
    }
}
