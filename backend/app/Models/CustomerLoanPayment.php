<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. Loan > Owe: one payment from a customer, split into
 * principal (lowers what they owe) and interest (income).
 */
#[Fillable([
    'customer_loan_id', 'paid_on', 'principal', 'interest', 'amount', 'cash_account_id',
    'principal_transaction_id', 'interest_transaction_id', 'note', 'created_by',
])]
class CustomerLoanPayment extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'paid_on' => 'date',
            'principal' => 'decimal:2',
            'interest' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<CustomerLoan, $this> */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(CustomerLoan::class, 'customer_loan_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'cash_account_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function auditModule(): string
    {
        return 'Loan';
    }
}
