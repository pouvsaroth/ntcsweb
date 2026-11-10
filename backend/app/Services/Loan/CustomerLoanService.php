<?php

declare(strict_types=1);

namespace App\Services\Loan;

use App\Models\Account;
use App\Models\CustomerLoan;
use App\Models\CustomerLoanPayment;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Services\Accounting\FinancialTransactionService;
use App\Support\Accounting\TransactionStatus;
use App\Support\Accounting\TransactionType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Loan > Owe: keeps the ledger in step with money lent to customers.
 *  - Money lent out of a Cash/Bank account: debit Loans Receivable, credit Cash/Bank (LENDING).
 *  - A payment's principal: debit Cash/Bank, credit Loans Receivable (LENDING).
 *  - A payment's interest: debit Cash/Bank, credit Loan Interest Income (INCOME).
 * Undoing any of these posts a reversal; nothing posted is edited.
 */
final class CustomerLoanService
{
    public function __construct(
        private readonly FinancialTransactionService $transactions,
        private readonly LoanLedgerAccounts $accounts,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): CustomerLoan
    {
        return DB::transaction(function () use ($data, $actor) {
            $loan = CustomerLoan::query()->create([...$data, 'created_by' => $actor->getKey()]);
            $this->postLent($loan, $actor);

            return $loan;
        });
    }

    /**
     * Terms that change the money are locked once a payment is recorded;
     * before that, a changed loan is reversed and posted again.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(CustomerLoan $loan, array $data, User $actor): CustomerLoan
    {
        return DB::transaction(function () use ($loan, $data, $actor) {
            $loan->fill($data);
            $moneyFields = ['loan_customer_id', 'amount', 'currency', 'lent_on', 'cash_account_id', 'interest_type', 'interest_rate', 'interest_period', 'term_months', 'first_payment_on'];
            $changed = array_values(array_filter($moneyFields, fn (string $field) => $loan->isDirty($field)));

            if ($changed !== [] && $loan->payments()->exists()) {
                throw ValidationException::withMessages([$changed[0] => __('Payments are recorded on this loan, so its amount and plan can no longer change. Remove the payments first.')]);
            }

            $postChanged = $loan->isDirty(['loan_customer_id', 'amount', 'currency', 'lent_on', 'cash_account_id']);
            $loan->save();

            if ($postChanged) {
                $this->reverseLent($loan, $actor);
                $this->postLent($loan, $actor);
            }

            return $loan;
        });
    }

    public function cancel(CustomerLoan $loan, User $actor): CustomerLoan
    {
        return DB::transaction(function () use ($loan, $actor) {
            $this->assertNoPayments($loan);
            $this->reverseLent($loan, $actor);
            $loan->update(['status' => 'cancelled']);

            return $loan;
        });
    }

    public function delete(CustomerLoan $loan, User $actor): void
    {
        DB::transaction(function () use ($loan, $actor) {
            $this->assertNoPayments($loan);
            $this->reverseLent($loan, $actor);
            $loan->delete();
        });
    }

    /**
     * @param  array{paid_on: string, principal?: float|int|string|null, interest?: float|int|string|null, cash_account_id: int, note?: string|null}  $data
     */
    public function collect(CustomerLoan $loan, array $data, User $actor): CustomerLoanPayment
    {
        return DB::transaction(function () use ($loan, $data, $actor) {
            /** @var CustomerLoan $loan */
            $loan = CustomerLoan::query()->whereKey($loan->getKey())->with('customer')->lockForUpdate()->firstOrFail();

            if ($loan->status !== 'active') {
                throw ValidationException::withMessages(['status' => __('Only an active loan can be paid.')]);
            }

            $decimals = $loan->currency === 'KHR' ? 0 : 2;
            $principal = round((float) ($data['principal'] ?? 0), $decimals);
            $interest = round((float) ($data['interest'] ?? 0), $decimals);
            $outstanding = $this->outstanding($loan);

            if ($principal < 0 || $interest < 0 || $principal + $interest <= 0) {
                throw ValidationException::withMessages(['principal' => __('Enter the principal or the interest paid.')]);
            }
            if ($principal > $outstanding + 0.001) {
                throw ValidationException::withMessages(['principal' => __('More than is still owed (:amount).', ['amount' => number_format($outstanding, $decimals)])]);
            }

            $cash = Account::query()->findOrFail($data['cash_account_id']);
            $name = trim(($loan->customer?->name ?? '').' '.$loan->loan_no);

            $payment = CustomerLoanPayment::query()->create([
                'customer_loan_id' => $loan->id,
                'paid_on' => $data['paid_on'],
                'principal' => $principal,
                'interest' => $interest,
                'amount' => $principal + $interest,
                'cash_account_id' => $cash->id,
                'note' => $data['note'] ?? null,
                'created_by' => $actor->getKey(),
            ]);

            if ($principal > 0) {
                $payment->principal_transaction_id = $this->transactions->postLending(
                    TransactionType::LENDING, $cash, $this->accounts->loansReceivable(), $principal, $loan->currency, $data['paid_on'],
                    "Loan payment from {$name}", $payment->getMorphClass(), $payment->id, $actor,
                )->id;
            }
            if ($interest > 0) {
                $payment->interest_transaction_id = $this->transactions->postLending(
                    TransactionType::INCOME, $cash, $this->accounts->loanInterestIncome(), $interest, $loan->currency, $data['paid_on'],
                    "Loan interest from {$name}", $payment->getMorphClass(), $payment->id, $actor,
                )->id;
            }
            $payment->save();

            if ($this->outstanding($loan) <= 0.001) {
                $loan->update(['status' => 'paid_off']);
            }

            return $payment;
        });
    }

    /** Undo a payment: its ledger entries are reversed. */
    public function removePayment(CustomerLoanPayment $payment, User $actor): CustomerLoan
    {
        return DB::transaction(function () use ($payment, $actor) {
            $loan = CustomerLoan::query()->whereKey($payment->customer_loan_id)->lockForUpdate()->firstOrFail();

            if ($loan->status === 'cancelled') {
                throw ValidationException::withMessages(['status' => __('This loan is cancelled.')]);
            }

            if ($payment->principal_transaction_id) {
                $this->reverseTransaction($payment->principal_transaction_id, TransactionType::LENDING, $actor);
            }
            if ($payment->interest_transaction_id) {
                $this->reverseTransaction($payment->interest_transaction_id, TransactionType::INCOME, $actor);
            }
            $payment->delete();

            if ($loan->status === 'paid_off' && $this->outstanding($loan) > 0.001) {
                $loan->update(['status' => 'active']);
            }

            return $loan;
        });
    }

    public function outstanding(CustomerLoan $loan): float
    {
        return round((float) $loan->amount - (float) $loan->payments()->sum('principal'), 2);
    }

    private function postLent(CustomerLoan $loan, User $actor): void
    {
        if (! $loan->cash_account_id || $loan->status === 'cancelled') {
            return;
        }

        $cash = Account::query()->findOrFail($loan->cash_account_id);
        $name = trim(($loan->customer()->value('name') ?? '').' '.$loan->loan_no);
        $transaction = $this->transactions->postLending(
            TransactionType::LENDING, $this->accounts->loansReceivable(), $cash, (float) $loan->amount, $loan->currency, $loan->lent_on->toDateString(),
            "Loan to {$name}", $loan->getMorphClass(), $loan->id, $actor,
        );
        $loan->forceFill(['lent_transaction_id' => $transaction->id])->save();
    }

    private function reverseLent(CustomerLoan $loan, User $actor): void
    {
        if (! $loan->lent_transaction_id) {
            return;
        }

        $this->reverseTransaction($loan->lent_transaction_id, TransactionType::LENDING, $actor);
        $loan->forceFill(['lent_transaction_id' => null])->save();
    }

    private function reverseTransaction(int $id, string $type, User $actor): void
    {
        $transaction = FinancialTransaction::query()->find($id);
        if ($transaction && $transaction->status === TransactionStatus::POSTED) {
            $this->transactions->reverse($transaction, $type, $actor);
        }
    }

    private function assertNoPayments(CustomerLoan $loan): void
    {
        if ($loan->payments()->exists()) {
            throw ValidationException::withMessages(['status' => __('Payments are recorded on this loan. Remove them first.')]);
        }
    }
}
