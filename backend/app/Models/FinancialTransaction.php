<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Accounting\TransactionStatus;
use Database\Factories\FinancialTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One posting to the general ledger — always exactly one debit account, one
 * credit account, one amount, so "total debit = total credit" holds by
 * construction (a deliberately simpler alternative to a multi-line
 * journal_entries/journal_entry_lines schema).
 *
 * This is still genuine double-entry — every event touches two accounts in
 * opposite directions — it just can't express a single entry with 3+ legs.
 * Nothing here is a business need yet, and the upgrade path stays cheap: any
 * row can be losslessly split into a 2-line journal entry later if a real
 * need for multi-leg postings ever appears.
 *
 * Does NOT use the Auditable trait — FinancialTransactionService/
 * ExpenseService fire their own explicit, richly-described audit entries
 * (TRANSACTION_POSTED, TRANSACTION_REVERSED, ...), the same reasoning as
 * Invoice/Payment.
 *
 * @property int $tenant_id
 * @property string $transaction_number
 * @property string $type
 * @property int $debit_account_id
 * @property int $credit_account_id
 * @property string $amount
 * @property string $status
 */
#[Fillable([
    'transaction_number', 'transaction_date', 'type', 'debit_account_id', 'credit_account_id',
    'amount', 'currency', 'description', 'reference_type', 'reference_id',
    'reverses_transaction_id', 'status', 'created_by',
])]
class FinancialTransaction extends Model
{
    /** @use HasFactory<FinancialTransactionFactory> */
    use BelongsToTenant, HasFactory;

    protected $attributes = [
        'currency' => 'USD',
        'status' => TransactionStatus::POSTED,
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'debit_account_id');
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'credit_account_id');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_transaction_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reverses_transaction_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isReversed(): bool
    {
        return $this->status === TransactionStatus::REVERSED;
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeOfType(Builder $query, string $type): void
    {
        $query->where('type', $type);
    }

    public function auditDisplayName(): string
    {
        return $this->transaction_number;
    }
}
