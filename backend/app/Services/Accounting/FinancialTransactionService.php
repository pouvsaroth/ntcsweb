<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Expense;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Accounting\TransactionStatus;
use App\Support\Accounting\TransactionType;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The one place a FinancialTransaction is written. Every posting is exactly
 * one debit account, one credit account, one amount — see the model's
 * docblock for why that's the right level of complexity here instead of a
 * multi-line journal entry.
 *
 * Revenue recognition is driven off Payment, not Invoice (cash-basis: money
 * is only "earned" in the ledger once actually received) — see
 * recognizeIncomeForPayment(). Reversing never edits or deletes a posted
 * row; it posts a new one with debit/credit swapped and marks the original
 * REVERSED, so the ledger's own running sum self-corrects.
 */
final class FinancialTransactionService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AccountingNumberGenerator $numbers,
        private readonly RevenueAccountResolver $revenueAccounts,
        private readonly AccountingPeriodGuard $periodGuard,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Splits the payment across the invoice's items (proportional to each
     * item's share of the invoice total, last item absorbing rounding),
     * groups by each item's resolved revenue account, and posts one INCOME
     * transaction per distinct account. Reference-based idempotency means
     * calling this twice for the same payment only ever posts once.
     *
     * Silently posts nothing (returns an empty collection) if no cash
     * account can be resolved at all — Billing must keep working even
     * before Accounting is configured; see AccountingSettingsController.
     *
     * @return Collection<int, FinancialTransaction>
     */
    public function recognizeIncomeForPayment(Payment $payment, User $actor): Collection
    {
        if ($this->alreadyPosted($payment)) {
            return new Collection;
        }

        $tenant = $this->context->getOrFail();
        $cashAccount = $this->resolveCashAccount($tenant, $payment->payment_method);

        if ($cashAccount === null) {
            return new Collection;
        }

        $this->periodGuard->assertOpen($tenant, Carbon::parse($payment->payment_date));

        /** @var Invoice $invoice */
        $invoice = $payment->invoice()->with('items.product')->firstOrFail();
        $allocations = $this->allocateAcrossItems($invoice, $tenant, (float) $payment->amount);

        $posted = new Collection;

        foreach ($allocations as [$revenueAccount, $amount]) {
            if ($amount <= 0) {
                continue;
            }

            $transaction = $this->post(
                number: $this->numbers->nextTransactionNumber($tenant),
                date: $payment->payment_date,
                type: TransactionType::INCOME,
                debitAccount: $cashAccount,
                creditAccount: $revenueAccount,
                amount: $amount,
                description: "Payment {$payment->payment_number} for invoice {$invoice->invoice_number}",
                actor: $actor,
                referenceType: $payment->getMorphClass(),
                referenceId: $payment->getKey(),
            );

            $posted->push($transaction);

            $this->audit->log(
                AuditAction::TRANSACTION_POSTED,
                'Accounting',
                $transaction,
                new: ['amount' => $amount, 'account' => $revenueAccount->auditDisplayName()],
                description: "Recognized revenue \${$amount} in {$revenueAccount->auditDisplayName()} from payment {$payment->payment_number}",
                actor: $actor,
            );
        }

        return $posted;
    }

    /**
     * Reverses every still-posted transaction previously recognized for this
     * payment — used when a payment is cancelled (type=ADJUSTMENT, an
     * accounting correction) or refunded (type=REFUND, money genuinely
     * returned). See PaymentService::cancel()/refund().
     *
     * @return Collection<int, FinancialTransaction>
     */
    public function reverseIncomeForPayment(Payment $payment, string $reversalType, User $actor): Collection
    {
        $originals = FinancialTransaction::query()
            ->where('reference_type', Payment::class)
            ->where('reference_id', $payment->getKey())
            ->where('status', TransactionStatus::POSTED)
            ->get();

        return $originals->map(fn (FinancialTransaction $original) => $this->reverse($original, $reversalType, $actor));
    }

    public function reverse(FinancialTransaction $original, string $reversalType, User $actor): FinancialTransaction
    {
        $tenant = $this->context->getOrFail();
        $original->loadMissing(['debitAccount', 'creditAccount']);

        $reversal = $this->post(
            number: $this->numbers->nextTransactionNumber($tenant),
            date: now()->toDateString(),
            type: $reversalType,
            debitAccount: $original->creditAccount,
            creditAccount: $original->debitAccount,
            amount: (float) $original->amount,
            description: "Reversal of {$original->transaction_number}",
            actor: $actor,
            referenceType: $original->getAttributes()['reference_type'] ?? null,
            referenceId: $original->getAttributes()['reference_id'] ?? null,
            reverses: $original,
        );

        $original->update(['status' => TransactionStatus::REVERSED]);

        $this->audit->log(
            AuditAction::TRANSACTION_REVERSED,
            'Accounting',
            $reversal,
            old: ['reverses' => $original->transaction_number],
            description: "Reversed transaction {$original->transaction_number} (\${$original->amount}) via {$reversal->transaction_number}",
            actor: $actor,
        );

        return $reversal;
    }

    public function createTransfer(Account $from, Account $to, float $amount, string $date, ?string $description, User $actor): FinancialTransaction
    {
        if (! $from->is_bank_or_cash || ! $to->is_bank_or_cash) {
            throw ValidationException::withMessages(['account' => 'A transfer can only move money between Cash/Bank accounts.']);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'The transfer amount must be greater than zero.']);
        }

        $tenant = $this->context->getOrFail();
        $this->periodGuard->assertOpen($tenant, Carbon::parse($date));

        $transaction = $this->post(
            number: $this->numbers->nextTransferNumber($tenant),
            date: $date,
            type: TransactionType::TRANSFER,
            debitAccount: $to,
            creditAccount: $from,
            amount: $amount,
            description: $description ?? "Transfer from {$from->auditDisplayName()} to {$to->auditDisplayName()}",
            actor: $actor,
        );

        $this->audit->log(
            AuditAction::TRANSFER_CREATED,
            'Accounting',
            $transaction,
            new: ['from' => $from->auditDisplayName(), 'to' => $to->auditDisplayName(), 'amount' => $amount],
            description: "Transferred \${$amount} from {$from->auditDisplayName()} to {$to->auditDisplayName()}",
            actor: $actor,
        );

        return $transaction;
    }

    /** The manual correction path from spec section 28 — a posted transaction is never edited, only adjusted. */
    public function createAdjustment(Account $debit, Account $credit, float $amount, string $date, string $description, User $actor): FinancialTransaction
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'The adjustment amount must be greater than zero.']);
        }

        $tenant = $this->context->getOrFail();
        $this->periodGuard->assertOpen($tenant, Carbon::parse($date));

        $transaction = $this->post(
            number: $this->numbers->nextTransactionNumber($tenant),
            date: $date,
            type: TransactionType::ADJUSTMENT,
            debitAccount: $debit,
            creditAccount: $credit,
            amount: $amount,
            description: $description,
            actor: $actor,
        );

        $this->audit->log(
            AuditAction::TRANSACTION_POSTED,
            'Accounting',
            $transaction,
            new: ['debit' => $debit->auditDisplayName(), 'credit' => $credit->auditDisplayName(), 'amount' => $amount],
            description: "Manual adjustment \${$amount}: {$description}",
            actor: $actor,
        );

        return $transaction;
    }

    /** Posts the EXPENSE-type transaction behind ExpenseService::pay() — debit the expense account, credit the cash/bank account it was paid from. */
    public function postExpensePayment(Account $expenseAccount, Account $cashAccount, float $amount, string $date, Expense $expense, User $actor): FinancialTransaction
    {
        $tenant = $this->context->getOrFail();
        $this->periodGuard->assertOpen($tenant, Carbon::parse($date));

        return $this->post(
            number: $this->numbers->nextTransactionNumber($tenant),
            date: $date,
            type: TransactionType::EXPENSE,
            debitAccount: $expenseAccount,
            creditAccount: $cashAccount,
            amount: $amount,
            description: "Paid expense {$expense->expense_number}: {$expense->description}",
            actor: $actor,
            referenceType: $expense->getMorphClass(),
            referenceId: $expense->getKey(),
        );
    }

    /** Manual "other income" entry (section 6) — a sale that never went through Invoice/Payment at all. */
    public function createManualIncome(Account $revenueAccount, Account $cashAccount, float $amount, string $date, ?string $description, User $actor): FinancialTransaction
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'The amount must be greater than zero.']);
        }

        $tenant = $this->context->getOrFail();
        $this->periodGuard->assertOpen($tenant, Carbon::parse($date));

        $transaction = $this->post(
            number: $this->numbers->nextTransactionNumber($tenant),
            date: $date,
            type: TransactionType::INCOME,
            debitAccount: $cashAccount,
            creditAccount: $revenueAccount,
            amount: $amount,
            description: $description,
            actor: $actor,
        );

        $this->audit->log(
            AuditAction::TRANSACTION_POSTED,
            'Accounting',
            $transaction,
            new: ['account' => $revenueAccount->auditDisplayName(), 'amount' => $amount],
            description: "Recorded manual income \${$amount} in {$revenueAccount->auditDisplayName()}".($description ? ": {$description}" : ''),
            actor: $actor,
        );

        return $transaction;
    }

    private function alreadyPosted(Payment $payment): bool
    {
        return FinancialTransaction::query()
            ->where('reference_type', Payment::class)
            ->where('reference_id', $payment->getKey())
            ->exists();
    }

    /**
     * @return list<array{0: Account, 1: float}>
     */
    private function allocateAcrossItems(Invoice $invoice, Tenant $tenant, float $paymentAmount): array
    {
        $items = $invoice->items;
        $invoiceTotal = (float) $invoice->total;

        if ($invoiceTotal <= 0 || $items->isEmpty()) {
            return [];
        }

        /** @var array<int, float> $amountsByAccount */
        $amountsByAccount = [];
        /** @var array<int, Account> $accountsById */
        $accountsById = [];

        $allocated = 0.0;
        $count = $items->count();

        $items->values()->each(function ($item, int $index) use (
            $tenant, $paymentAmount, $invoiceTotal, $count, &$allocated, &$amountsByAccount, &$accountsById,
        ) {
            $isLast = $index === $count - 1;
            $share = (float) $item->total / $invoiceTotal;
            $amount = $isLast
                ? round($paymentAmount - $allocated, 2)
                : round($paymentAmount * $share, 2);
            $allocated += $amount;

            $account = $this->revenueAccounts->forProduct($item->product, $tenant);

            if ($account === null) {
                return;
            }

            $amountsByAccount[$account->id] = ($amountsByAccount[$account->id] ?? 0) + $amount;
            $accountsById[$account->id] = $account;
        });

        return collect($amountsByAccount)
            ->map(fn (float $amount, int $accountId) => [$accountsById[$accountId], $amount])
            ->values()
            ->all();
    }

    private function resolveCashAccount(Tenant $tenant, ?string $paymentMethod): ?Account
    {
        if ($paymentMethod !== null) {
            $mappedId = $tenant->setting("accounting.payment_method_accounts.{$paymentMethod}");

            if ($mappedId !== null) {
                $account = Account::query()->find($mappedId);

                if ($account !== null) {
                    return $account;
                }
            }
        }

        $defaultId = $tenant->setting('accounting.default_cash_account_id');

        return $defaultId !== null ? Account::query()->find($defaultId) : null;
    }

    private function post(
        string $number,
        string|CarbonInterface $date,
        string $type,
        Account $debitAccount,
        Account $creditAccount,
        float $amount,
        ?string $description,
        User $actor,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?FinancialTransaction $reverses = null,
    ): FinancialTransaction {
        return FinancialTransaction::query()->create([
            'transaction_number' => $number,
            'transaction_date' => $date,
            'type' => $type,
            'debit_account_id' => $debitAccount->getKey(),
            'credit_account_id' => $creditAccount->getKey(),
            'amount' => round($amount, 2),
            'description' => $description,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'reverses_transaction_id' => $reverses?->getKey(),
            'created_by' => $actor->getKey(),
        ]);
    }
}
