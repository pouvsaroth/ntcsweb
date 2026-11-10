<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. Loan > Savings: one deposit or withdrawal (with a remark).
 * `interest` is reserved for when savings start earning interest.
 */
#[Fillable(['savings_account_id', 'type', 'amount', 'transacted_on', 'remark', 'cash_account_id', 'transaction_id', 'created_by'])]
class SavingsTransaction extends Model
{
    use Auditable;

    public const DEPOSIT = 'deposit';

    public const WITHDRAWAL = 'withdrawal';

    public const INTEREST = 'interest';

    /** Types a person can record now (interest comes later). */
    public const RECORDABLE = [self::DEPOSIT, self::WITHDRAWAL];

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'transacted_on' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    /** +amount for money in, -amount for money out. */
    public function signedAmount(): float
    {
        return $this->type === self::WITHDRAWAL ? -(float) $this->amount : (float) $this->amount;
    }

    /** @return BelongsTo<SavingsAccount, $this> */
    public function savingsAccount(): BelongsTo
    {
        return $this->belongsTo(SavingsAccount::class);
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
