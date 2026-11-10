<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\CustomerLoanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned. Loan > Owe: money lent to a customer, who pays it back
 * monthly over `term_months` from `first_payment_on` (see BorrowingSchedule)
 * or, without `term_months`, flexibly. `loan_no` (L00001) comes from the id.
 */
#[Fillable([
    'loan_customer_id', 'reference', 'amount', 'currency', 'lent_on', 'cash_account_id',
    'interest_type', 'interest_rate', 'interest_period', 'term_months', 'first_payment_on', 'purpose',
    'status', 'created_by',
])]
class CustomerLoan extends Model
{
    /** @use HasFactory<CustomerLoanFactory> */
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
            'lent_on' => 'date',
            'first_payment_on' => 'date',
            'term_months' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (CustomerLoan $loan) {
            if ($loan->loan_no === null) {
                $loan->forceFill(['loan_no' => sprintf('L%05d', $loan->id)])->saveQuietly();
            }
        });
    }

    /** @return BelongsTo<LoanCustomer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(LoanCustomer::class, 'loan_customer_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'cash_account_id');
    }

    /** @return HasMany<CustomerLoanPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(CustomerLoanPayment::class);
    }

    public function auditModule(): string
    {
        return 'Loan';
    }
}
