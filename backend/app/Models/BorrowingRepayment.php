<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. One payment back to a lender: principal lowers Loans Payable,
 * interest is a paid Expense (`interest_expense_id`) tagged with the loan's
 * Borrowed for. Written only by BorrowingService.
 */
#[Fillable(['borrowing_id', 'paid_on', 'principal', 'interest', 'amount', 'cash_account_id', 'principal_transaction_id', 'interest_expense_id', 'note', 'created_by'])]
class BorrowingRepayment extends Model
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

    public function borrowing(): BelongsTo
    {
        return $this->belongsTo(Borrowing::class);
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'cash_account_id');
    }

    public function interestExpense(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'interest_expense_id');
    }

    public function auditModule(): string
    {
        return 'Accounting';
    }
}
