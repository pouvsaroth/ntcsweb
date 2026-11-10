<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\SavingsAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned. Loan > Savings: one customer's savings in one currency.
 * `account_no` (S00001) is given from the id on create. The balance is the
 * sum of its entries (deposits and interest in, withdrawals out).
 */
#[Fillable(['loan_customer_id', 'currency', 'opened_on', 'status', 'notes', 'created_by'])]
class SavingsAccount extends Model
{
    /** @use HasFactory<SavingsAccountFactory> */
    use Auditable, HasFactory;

    public const STATUSES = ['open', 'closed'];

    protected $connection = 'tenant';

    protected $attributes = [
        'status' => 'open',
    ];

    protected function casts(): array
    {
        return [
            'opened_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (SavingsAccount $account) {
            if ($account->account_no === null) {
                $account->forceFill(['account_no' => sprintf('S%05d', $account->id)])->saveQuietly();
            }
        });
    }

    /** @return BelongsTo<LoanCustomer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(LoanCustomer::class, 'loan_customer_id');
    }

    /** @return HasMany<SavingsTransaction, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(SavingsTransaction::class);
    }

    public function auditModule(): string
    {
        return 'Loan';
    }
}
