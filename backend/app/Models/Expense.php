<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Accounting\ExpenseStatus;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A school expense — see ExpenseStatus for its DRAFT/PENDING_APPROVAL/
 * APPROVED/PAID/REJECTED/CANCELLED lifecycle, driven entirely by
 * ExpenseService. Never physically deleted: a mistaken expense is
 * CANCELLED (before payment) or corrected via a manual adjustment
 * transaction (after payment) — see FinancialTransactionService::
 * createAdjustment(). Does NOT use the Auditable trait — same reasoning as
 * Invoice/Payment/FinancialTransaction.
 *
 * @property int $tenant_id
 * @property string $expense_number
 * @property int $account_id
 * @property string $amount
 * @property string $status
 */
#[Fillable([
    'expense_number', 'expense_date', 'account_id', 'cash_account_id', 'amount', 'payment_method',
    'vendor', 'description', 'reference_number', 'status', 'created_by',
    'approved_by', 'approved_at', 'rejected_reason', 'paid_at',
    'cancellation_reason', 'cancelled_by', 'cancelled_at',
])]
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use BelongsToTenant, HasFactory;

    protected $attributes = [
        'status' => ExpenseStatus::PENDING_APPROVAL,
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'cash_account_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ExpenseAttachment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isClosed(): bool
    {
        return ExpenseStatus::isClosed($this->status);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', ExpenseStatus::PENDING_APPROVAL);
    }

    public function auditDisplayName(): string
    {
        return $this->expense_number;
    }
}
