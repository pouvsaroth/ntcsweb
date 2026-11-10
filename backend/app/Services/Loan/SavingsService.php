<?php

declare(strict_types=1);

namespace App\Services\Loan;

use App\Models\Account;
use App\Models\FinancialTransaction;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\Accounting\FinancialTransactionService;
use App\Support\Accounting\AccountType;
use App\Support\Accounting\TransactionStatus;
use App\Support\Accounting\TransactionType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Loan > Savings: keeps each savings account and the ledger in step.
 *  - Deposit: debit Cash/Bank, credit Customer Savings (money owed back to the customer).
 *  - Withdrawal: debit Customer Savings, credit Cash/Bank.
 * The balance may never go below zero on any day, so a withdrawal (or
 * removing a deposit) that would overdraw the account is refused.
 * Removing an entry posts a reversal; nothing posted is edited.
 */
final class SavingsService
{
    private const CUSTOMER_SAVINGS = ['code' => '2200', 'name' => 'Customer Savings', 'type' => AccountType::LIABILITY, 'parent' => '2000'];

    public function __construct(
        private readonly FinancialTransactionService $transactions,
    ) {}

    /**
     * @param  array{loan_customer_id: int, currency: string, opened_on: string, notes?: string|null}  $data
     * @param  array{amount: float|int|string, cash_account_id: int, remark?: string|null}|null  $firstDeposit
     */
    public function open(array $data, ?array $firstDeposit, User $actor): SavingsAccount
    {
        return DB::transaction(function () use ($data, $firstDeposit, $actor) {
            $account = SavingsAccount::query()->create([...$data, 'created_by' => $actor->getKey()]);

            if ($firstDeposit !== null) {
                $this->record($account, [
                    'type' => SavingsTransaction::DEPOSIT,
                    'amount' => $firstDeposit['amount'],
                    'transacted_on' => $data['opened_on'],
                    'cash_account_id' => $firstDeposit['cash_account_id'],
                    'remark' => $firstDeposit['remark'] ?? null,
                ], $actor);
            }

            return $account;
        });
    }

    /**
     * @param  array{type: string, amount: float|int|string, transacted_on: string, cash_account_id: int, remark?: string|null}  $data
     */
    public function record(SavingsAccount $account, array $data, User $actor): SavingsTransaction
    {
        return DB::transaction(function () use ($account, $data, $actor) {
            /** @var SavingsAccount $account */
            $account = SavingsAccount::query()->whereKey($account->getKey())->with('customer')->lockForUpdate()->firstOrFail();

            if ($account->status !== 'open') {
                throw ValidationException::withMessages(['status' => __('This savings account is closed.')]);
            }
            if (Carbon::parse($data['transacted_on'])->lt($account->opened_on)) {
                throw ValidationException::withMessages(['transacted_on' => __('The date is before the account was opened.')]);
            }

            $amount = round((float) $data['amount'], $account->currency === 'KHR' ? 0 : 2);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => __('The amount must be greater than zero.')]);
            }

            $entry = new SavingsTransaction([
                'savings_account_id' => $account->id,
                'type' => $data['type'],
                'amount' => $amount,
                'transacted_on' => $data['transacted_on'],
                'remark' => $data['remark'] ?? null,
                'cash_account_id' => $data['cash_account_id'],
                'created_by' => $actor->getKey(),
            ]);

            if ($entry->type === SavingsTransaction::WITHDRAWAL) {
                $this->assertNeverOverdrawn($account, add: $entry, field: 'amount');
            }
            $entry->save();

            $cash = Account::query()->findOrFail($entry->cash_account_id);
            $savings = $this->customerSavings();
            $name = trim(($account->customer?->name ?? '').' '.$account->account_no);
            $transaction = $entry->type === SavingsTransaction::WITHDRAWAL
                ? $this->transactions->postSavings($savings, $cash, $amount, $account->currency, $entry->transacted_on->toDateString(), "Savings withdrawal — {$name}", $entry->getMorphClass(), $entry->id, $actor)
                : $this->transactions->postSavings($cash, $savings, $amount, $account->currency, $entry->transacted_on->toDateString(), "Savings deposit — {$name}", $entry->getMorphClass(), $entry->id, $actor);
            $entry->forceFill(['transaction_id' => $transaction->id])->save();

            return $entry;
        });
    }

    /** Undo an entry: its ledger entry is reversed. A deposit that later withdrawals relied on cannot go. */
    public function remove(SavingsTransaction $entry, User $actor): SavingsAccount
    {
        return DB::transaction(function () use ($entry, $actor) {
            $account = SavingsAccount::query()->whereKey($entry->savings_account_id)->lockForUpdate()->firstOrFail();

            if ($entry->type !== SavingsTransaction::WITHDRAWAL) {
                $this->assertNeverOverdrawn($account, without: $entry, field: 'entry');
            }

            if ($entry->transaction_id) {
                $transaction = FinancialTransaction::query()->find($entry->transaction_id);
                if ($transaction && $transaction->status === TransactionStatus::POSTED) {
                    $this->transactions->reverse($transaction, TransactionType::SAVINGS, $actor);
                }
            }
            $entry->delete();

            return $account;
        });
    }

    public function balance(SavingsAccount $account): float
    {
        return round($account->entries()->get()->sum(fn (SavingsTransaction $e) => $e->signedAmount()), 2);
    }

    /**
     * Entries oldest first, each with the balance after it.
     *
     * @return list<array{entry: SavingsTransaction, balance: float}>
     */
    public function statement(SavingsAccount $account): array
    {
        $balance = 0.0;
        $rows = [];
        foreach ($account->entries()->with(['cashAccount', 'creator'])->orderBy('transacted_on')->orderBy('id')->get() as $entry) {
            $balance = round($balance + $entry->signedAmount(), 2);
            $rows[] = ['entry' => $entry, 'balance' => $balance];
        }

        return $rows;
    }

    public function customerSavings(): Account
    {
        $spec = self::CUSTOMER_SAVINGS;
        $found = Account::query()->where('code', $spec['code'])->where('type', $spec['type'])->first()
            ?? Account::query()->where('name', $spec['name'])->where('type', $spec['type'])->orderBy('id')->first();
        if ($found) {
            return $found;
        }

        // Schools set up before Savings don't have it yet.
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

    /**
     * Walk the entries in date order (with one added or one left out) and
     * refuse if the balance would drop below zero on any day.
     */
    private function assertNeverOverdrawn(SavingsAccount $account, ?SavingsTransaction $add = null, ?SavingsTransaction $without = null, string $field = 'amount'): void
    {
        $entries = $account->entries()->get()
            ->reject(fn (SavingsTransaction $e) => $without !== null && $e->id === $without->id)
            ->values();
        if ($add !== null) {
            $entries->push($add);
        }

        // Same day: money in counts before money out, so a same-day deposit covers a withdrawal.
        $sorted = $entries->sort(function (SavingsTransaction $a, SavingsTransaction $b) {
            return [$a->transacted_on->toDateString(), $a->type === SavingsTransaction::WITHDRAWAL ? 1 : 0, $a->id ?? PHP_INT_MAX]
                <=> [$b->transacted_on->toDateString(), $b->type === SavingsTransaction::WITHDRAWAL ? 1 : 0, $b->id ?? PHP_INT_MAX];
        });

        $decimals = $account->currency === 'KHR' ? 0 : 2;
        $balance = 0.0;
        foreach ($sorted as $entry) {
            $balance = round($balance + $entry->signedAmount(), 2);
            if ($balance < -0.001) {
                $message = $add !== null
                    ? __('Not enough savings: the balance would go below zero on :date (short by :amount).', ['date' => $entry->transacted_on->format('d-m-Y'), 'amount' => number_format(-$balance, $decimals)])
                    : __('This deposit is needed for later withdrawals, so it cannot be removed.');
                throw ValidationException::withMessages([$field => $message]);
            }
        }
    }
}
