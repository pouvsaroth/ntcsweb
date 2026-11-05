<?php

declare(strict_types=1);

namespace App\Services\Borrowings;

use App\Models\Account;
use App\Models\Borrowing;
use App\Models\BorrowingRepayment;
use App\Models\Expense;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Services\Accounting\AccountingNumberGenerator;
use App\Services\Accounting\FinancialTransactionService;
use App\Support\Accounting\AccountType;
use App\Support\Accounting\ExpenseStatus;
use App\Support\Accounting\TransactionStatus;
use App\Support\Accounting\TransactionType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Accounting > Borrowings: keeps the ledger in step with loans.
 *  - Money received into a Cash/Bank account: debit Cash/Bank, credit Loans Payable.
 *  - A repayment's principal: debit Loans Payable, credit Cash/Bank.
 *  - A repayment's interest: a paid Expense (Loan Interest), tagged with the
 *    loan's Borrowed for, so it shows under Expenses and in Profit & Loss.
 * Undoing any of these posts a reversal; nothing posted is edited.
 */
final class BorrowingService
{
    private const LOANS_PAYABLE = ['code' => '2100', 'name' => 'Loans Payable', 'type' => AccountType::LIABILITY, 'parent' => '2000'];

    private const LOAN_INTEREST = ['code' => '5950', 'name' => 'Loan Interest', 'type' => AccountType::EXPENSE, 'parent' => '5000'];

    public function __construct(
        private readonly FinancialTransactionService $transactions,
        private readonly AccountingNumberGenerator $numbers,
        private readonly TenantContext $context,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): Borrowing
    {
        return DB::transaction(function () use ($data, $actor) {
            $loan = Borrowing::query()->create([...$data, 'created_by' => $actor->getKey()]);
            $this->postReceipt($loan, $actor);

            return $loan;
        });
    }

    /**
     * Terms that change the money (amount, currency, date, account, plan)
     * are locked once a payment is recorded; before that, a changed receipt
     * is reversed and posted again.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Borrowing $loan, array $data, User $actor): Borrowing
    {
        return DB::transaction(function () use ($loan, $data, $actor) {
            $loan->fill($data);
            $moneyFields = ['amount', 'currency', 'received_on', 'cash_account_id', 'interest_type', 'interest_rate', 'interest_period', 'term_months', 'first_payment_on'];
            $changed = array_values(array_filter($moneyFields, fn (string $field) => $loan->isDirty($field)));

            if ($changed !== [] && $loan->repayments()->exists()) {
                throw ValidationException::withMessages([$changed[0] => __('Payments are recorded on this loan, so its amount and plan can no longer change. Remove the payments first.')]);
            }

            $receiptChanged = $loan->isDirty(['amount', 'currency', 'received_on', 'cash_account_id']);
            $loan->save();

            if ($receiptChanged) {
                $this->reverseReceipt($loan, $actor);
                $this->postReceipt($loan, $actor);
            }

            return $loan;
        });
    }

    public function cancel(Borrowing $loan, User $actor): Borrowing
    {
        return DB::transaction(function () use ($loan, $actor) {
            $this->assertNoRepayments($loan);
            $this->reverseReceipt($loan, $actor);
            $loan->update(['status' => 'cancelled']);

            return $loan;
        });
    }

    public function delete(Borrowing $loan, User $actor): void
    {
        DB::transaction(function () use ($loan, $actor) {
            $this->assertNoRepayments($loan);
            $this->reverseReceipt($loan, $actor);
            $loan->delete();
        });
    }

    /**
     * @param  array{paid_on: string, principal?: float|int|string|null, interest?: float|int|string|null, cash_account_id: int, note?: string|null}  $data
     */
    public function repay(Borrowing $loan, array $data, User $actor): BorrowingRepayment
    {
        return DB::transaction(function () use ($loan, $data, $actor) {
            /** @var Borrowing $loan */
            $loan = Borrowing::query()->whereKey($loan->getKey())->with('lender')->lockForUpdate()->firstOrFail();

            if ($loan->status !== 'active') {
                throw ValidationException::withMessages(['status' => __('Only an active loan can be paid.')]);
            }

            $decimals = $loan->currency === 'KHR' ? 0 : 2;
            $principal = round((float) ($data['principal'] ?? 0), $decimals);
            $interest = round((float) ($data['interest'] ?? 0), $decimals);
            $outstanding = $this->outstanding($loan);

            if ($principal + $interest <= 0) {
                throw ValidationException::withMessages(['principal' => __('Enter the principal or the interest paid.')]);
            }
            if ($principal > $outstanding + 0.001) {
                throw ValidationException::withMessages(['principal' => __('More than is still owed (:amount).', ['amount' => number_format($outstanding, $decimals)])]);
            }

            $cash = Account::query()->findOrFail($data['cash_account_id']);
            $lenderName = $loan->lender?->name ?? '';

            $repayment = BorrowingRepayment::query()->create([
                'borrowing_id' => $loan->id,
                'paid_on' => $data['paid_on'],
                'principal' => $principal,
                'interest' => $interest,
                'amount' => $principal + $interest,
                'cash_account_id' => $cash->id,
                'note' => $data['note'] ?? null,
                'created_by' => $actor->getKey(),
            ]);

            if ($principal > 0) {
                $transaction = $this->transactions->postLoan(
                    $this->loansPayable(), $cash, $principal, $loan->currency, $data['paid_on'],
                    trim("Loan repayment to {$lenderName} {$loan->reference}"),
                    $repayment->getMorphClass(), $repayment->id, $actor,
                );
                $repayment->principal_transaction_id = $transaction->id;
            }

            if ($interest > 0) {
                $repayment->interest_expense_id = $this->payInterest($loan, $repayment, $interest, $cash, $actor)->id;
            }
            $repayment->save();

            if ($this->outstanding($loan) <= 0.001) {
                $loan->update(['status' => 'paid_off']);
            }

            return $repayment;
        });
    }

    /** Undo a payment: its ledger entries are reversed, its interest expense cancelled. */
    public function removeRepayment(BorrowingRepayment $repayment, User $actor): Borrowing
    {
        return DB::transaction(function () use ($repayment, $actor) {
            $loan = Borrowing::query()->whereKey($repayment->borrowing_id)->lockForUpdate()->firstOrFail();

            if ($loan->status === 'cancelled') {
                throw ValidationException::withMessages(['status' => __('This loan is cancelled.')]);
            }

            if ($repayment->principal_transaction_id) {
                $this->reverseTransaction($repayment->principal_transaction_id, TransactionType::LOAN, $actor);
            }

            if ($repayment->interest_expense_id) {
                $expense = Expense::query()->find($repayment->interest_expense_id);
                if ($expense) {
                    FinancialTransaction::query()
                        ->where('reference_type', $expense->getMorphClass())
                        ->where('reference_id', $expense->id)
                        ->where('status', TransactionStatus::POSTED)
                        ->whereNull('reverses_transaction_id')
                        ->get()
                        ->each(fn (FinancialTransaction $t) => $this->transactions->reverse($t, TransactionType::EXPENSE, $actor));
                    $expense->update([
                        'status' => ExpenseStatus::CANCELLED,
                        'cancellation_reason' => 'Loan repayment removed',
                        'cancelled_by' => $actor->getKey(),
                        'cancelled_at' => now(),
                    ]);
                }
            }

            $repayment->delete();

            if ($loan->status === 'paid_off' && $this->outstanding($loan) > 0.001) {
                $loan->update(['status' => 'active']);
            }

            return $loan;
        });
    }

    public function outstanding(Borrowing $loan): float
    {
        return round((float) $loan->amount - (float) $loan->repayments()->sum('principal'), 2);
    }

    public function loansPayable(): Account
    {
        return $this->account(self::LOANS_PAYABLE);
    }

    public function loanInterest(): Account
    {
        return $this->account(self::LOAN_INTEREST);
    }

    private function postReceipt(Borrowing $loan, User $actor): void
    {
        if (! $loan->cash_account_id || $loan->status === 'cancelled') {
            return;
        }

        $cash = Account::query()->findOrFail($loan->cash_account_id);
        $lenderName = $loan->lender()->value('name') ?? '';
        $transaction = $this->transactions->postLoan(
            $cash, $this->loansPayable(), (float) $loan->amount, $loan->currency, $loan->received_on->toDateString(),
            trim("Loan from {$lenderName} {$loan->reference}"),
            $loan->getMorphClass(), $loan->id, $actor,
        );
        $loan->forceFill(['receipt_transaction_id' => $transaction->id])->save();
    }

    private function reverseReceipt(Borrowing $loan, User $actor): void
    {
        if (! $loan->receipt_transaction_id) {
            return;
        }

        $this->reverseTransaction($loan->receipt_transaction_id, TransactionType::LOAN, $actor);
        $loan->forceFill(['receipt_transaction_id' => null])->save();
    }

    private function reverseTransaction(int $id, string $type, User $actor): void
    {
        $transaction = FinancialTransaction::query()->find($id);
        if ($transaction && $transaction->status === TransactionStatus::POSTED) {
            $this->transactions->reverse($transaction, $type, $actor);
        }
    }

    private function payInterest(Borrowing $loan, BorrowingRepayment $repayment, float $interest, Account $cash, User $actor): Expense
    {
        $tenant = $this->context->getOrFail();
        $lender = $loan->lender;

        $expense = Expense::query()->create([
            'expense_number' => $this->numbers->nextExpenseNumber($tenant),
            'expense_date' => $repayment->paid_on->toDateString(),
            'account_id' => $this->loanInterest()->id,
            'expense_entity_id' => $loan->expense_entity_id,
            'amount' => $interest,
            'currency' => $loan->currency,
            'vendor' => $lender?->name,
            'description' => trim("Loan interest — {$lender?->name} {$loan->reference}"),
            'status' => ExpenseStatus::PAID,
            'cash_account_id' => $cash->id,
            'created_by' => $actor->getKey(),
            'approved_by' => $actor->getKey(),
            'approved_at' => now(),
            'paid_at' => $repayment->paid_on,
            'reference_type' => $repayment->getMorphClass(),
            'reference_id' => $repayment->id,
        ]);

        $this->transactions->postExpensePayment($this->loanInterest(), $cash, $interest, $repayment->paid_on->toDateString(), $expense, $actor);

        return $expense;
    }

    private function assertNoRepayments(Borrowing $loan): void
    {
        if ($loan->repayments()->exists()) {
            throw ValidationException::withMessages(['status' => __('Payments are recorded on this loan. Remove them first.')]);
        }
    }

    /**
     * The school's account for this (by its usual code, else by name), added
     * when missing — schools set up before Borrowings don't have it.
     *
     * @param  array{code: string, name: string, type: string, parent: string}  $spec
     */
    private function account(array $spec): Account
    {
        $found = Account::query()->where('code', $spec['code'])->where('type', $spec['type'])->first()
            ?? Account::query()->where('name', $spec['name'])->where('type', $spec['type'])->orderBy('id')->first();
        if ($found) {
            return $found;
        }

        $code = (int) $spec['code'];
        while (Account::query()->where('code', (string) $code)->exists()) {
            $code += 10;
        }

        return Account::query()->create([
            'code' => (string) $code,
            'name' => $spec['name'],
            'type' => $spec['type'],
            'parent_id' => Account::query()->where('code', $spec['parent'])->where('type', $spec['type'])->value('id'),
            'is_bank_or_cash' => false,
        ]);
    }
}
