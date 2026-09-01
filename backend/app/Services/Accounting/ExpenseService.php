<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Expense;
use App\Models\User;
use App\Support\Accounting\ExpenseStatus;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The Expense approval workflow: create -> PENDING_APPROVAL -> APPROVED ->
 * PAID, or REJECTED/CANCELLED along the way. Only pay() ever touches the
 * ledger (via FinancialTransactionService::postExpensePayment()) — an
 * approved-but-unpaid expense has no financial-transaction row yet, matching
 * cash-basis accounting (an expense is only "spent" once money actually
 * leaves an account).
 */
final class ExpenseService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AccountingNumberGenerator $numbers,
        private readonly FinancialTransactionService $transactions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{expense_date?:string, account_id:int, amount:float, payment_method?:string|null, vendor?:string|null, description?:string|null, reference_number?:string|null, status?:string}  $data
     */
    public function create(array $data, User $actor): Expense
    {
        return DB::transaction(function () use ($data, $actor) {
            $tenant = $this->context->getOrFail();

            $expense = Expense::query()->create([
                'expense_number' => $this->numbers->nextExpenseNumber($tenant),
                'expense_date' => $data['expense_date'] ?? now()->toDateString(),
                'account_id' => $data['account_id'],
                'amount' => round((float) $data['amount'], 2),
                'payment_method' => $data['payment_method'] ?? null,
                'vendor' => $data['vendor'] ?? null,
                'description' => $data['description'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'status' => $data['status'] ?? ExpenseStatus::PENDING_APPROVAL,
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->log(
                AuditAction::EXPENSE_CREATED,
                'Expenses',
                $expense,
                new: ['amount' => (float) $expense->amount, 'account_id' => $expense->account_id],
                description: "Created expense {$expense->expense_number} — \${$expense->amount}",
                actor: $actor,
            );

            return $expense;
        });
    }

    /** Segregation of duties: whoever created the expense can never approve it, no exceptions. */
    public function approve(Expense $expense, User $actor): Expense
    {
        return DB::transaction(function () use ($expense, $actor) {
            /** @var Expense $expense */
            $expense = Expense::query()->whereKey($expense->getKey())->lockForUpdate()->firstOrFail();

            if ($expense->status !== ExpenseStatus::PENDING_APPROVAL) {
                throw ValidationException::withMessages(['status' => 'Only a pending expense can be approved.']);
            }

            if ($expense->created_by === $actor->getKey()) {
                throw ValidationException::withMessages(['status' => 'You cannot approve an expense you created yourself.']);
            }

            $expense->update([
                'status' => ExpenseStatus::APPROVED,
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
            ]);

            $this->audit->log(
                AuditAction::EXPENSE_APPROVED,
                'Expenses',
                $expense,
                description: "Approved expense {$expense->expense_number}",
                actor: $actor,
            );

            return $expense;
        });
    }

    public function reject(Expense $expense, string $reason, User $actor): Expense
    {
        return DB::transaction(function () use ($expense, $reason, $actor) {
            /** @var Expense $expense */
            $expense = Expense::query()->whereKey($expense->getKey())->lockForUpdate()->firstOrFail();

            if ($expense->status !== ExpenseStatus::PENDING_APPROVAL) {
                throw ValidationException::withMessages(['status' => 'Only a pending expense can be rejected.']);
            }

            $expense->update(['status' => ExpenseStatus::REJECTED, 'rejected_reason' => $reason]);

            $this->audit->log(
                AuditAction::EXPENSE_REJECTED,
                'Expenses',
                $expense,
                new: ['reason' => $reason],
                description: "Rejected expense {$expense->expense_number}: {$reason}",
                actor: $actor,
            );

            return $expense;
        });
    }

    public function pay(Expense $expense, Account $cashAccount, User $actor, ?string $date = null): Expense
    {
        return DB::transaction(function () use ($expense, $cashAccount, $actor, $date) {
            /** @var Expense $expense */
            $expense = Expense::query()->whereKey($expense->getKey())->with('account')->lockForUpdate()->firstOrFail();

            if ($expense->status !== ExpenseStatus::APPROVED) {
                throw ValidationException::withMessages(['status' => 'Only an approved expense can be paid.']);
            }

            if (! $cashAccount->is_bank_or_cash) {
                throw ValidationException::withMessages(['cash_account_id' => 'Expenses can only be paid from a Cash/Bank account.']);
            }

            $payDate = $date ?? now()->toDateString();

            $this->transactions->postExpensePayment(
                $expense->account,
                $cashAccount,
                (float) $expense->amount,
                $payDate,
                $expense,
                $actor,
            );

            $expense->update([
                'status' => ExpenseStatus::PAID,
                'cash_account_id' => $cashAccount->getKey(),
                'paid_at' => Carbon::parse($payDate),
            ]);

            $this->audit->log(
                AuditAction::EXPENSE_PAID,
                'Expenses',
                $expense,
                new: ['cash_account' => $cashAccount->auditDisplayName(), 'amount' => (float) $expense->amount],
                description: "Paid expense {$expense->expense_number} — \${$expense->amount} from {$cashAccount->auditDisplayName()}",
                actor: $actor,
            );

            return $expense;
        });
    }

    /** Only DRAFT/PENDING_APPROVAL/APPROVED can be cancelled — a PAID expense needs a manual adjustment instead (spec section 28: never silently edit a posted transaction). */
    public function cancel(Expense $expense, string $reason, User $actor): Expense
    {
        return DB::transaction(function () use ($expense, $reason, $actor) {
            /** @var Expense $expense */
            $expense = Expense::query()->whereKey($expense->getKey())->lockForUpdate()->firstOrFail();

            if ($expense->isClosed()) {
                throw ValidationException::withMessages(['status' => 'This expense is already paid, rejected, or cancelled.']);
            }

            $expense->update([
                'status' => ExpenseStatus::CANCELLED,
                'cancellation_reason' => $reason,
                'cancelled_by' => $actor->getKey(),
                'cancelled_at' => now(),
            ]);

            $this->audit->log(
                AuditAction::EXPENSE_CANCELLED,
                'Expenses',
                $expense,
                new: ['reason' => $reason],
                description: "Cancelled expense {$expense->expense_number}: {$reason}",
                actor: $actor,
            );

            return $expense;
        });
    }
}
