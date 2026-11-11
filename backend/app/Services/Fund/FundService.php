<?php

declare(strict_types=1);

namespace App\Services\Fund;

use App\Models\Account;
use App\Models\FinancialTransaction;
use App\Models\Fund;
use App\Models\FundTransaction;
use App\Models\User;
use App\Services\Accounting\FinancialTransactionService;
use App\Support\Accounting\AccountType;
use App\Support\Accounting\TransactionStatus;
use App\Support\Accounting\TransactionType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fund > Funds: keeps each fund and the ledger in step.
 *  - Money in: debit Cash/Bank, credit Funds (equity set aside for the fund).
 *  - Money out: debit Funds, credit Cash/Bank.
 * A fund can never go below zero on any day, so money out (or removing
 * money in) that would overdraw it is refused. Removing an entry posts a
 * reversal; nothing posted is edited.
 */
final class FundService
{
    /** The ledger account every fund posts to (found by code, else name; added when missing). */
    private const FUNDS_ACCOUNT = ['code' => '3200', 'name' => 'Funds', 'type' => AccountType::EQUITY, 'parent' => '3000'];

    public function __construct(private readonly FinancialTransactionService $transactions) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array{amount: float|int|string, cash_account_id: int, party?: string|null, fund_provider_id?: int|null, remark?: string|null}|null  $opening
     */
    public function create(array $data, ?array $opening, User $actor): Fund
    {
        return DB::transaction(function () use ($data, $opening, $actor) {
            $fund = Fund::query()->create([...$data, 'created_by' => $actor->getKey()]);

            if ($opening !== null) {
                $this->record($fund, [
                    'type' => FundTransaction::IN,
                    'amount' => $opening['amount'],
                    'transacted_on' => $fund->opened_on->toDateString(),
                    'cash_account_id' => $opening['cash_account_id'],
                    'party' => $opening['party'] ?? null,
                    'fund_provider_id' => $opening['fund_provider_id'] ?? null,
                    'remark' => $opening['remark'] ?? null,
                ], $actor);
            }

            return $fund;
        });
    }

    /**
     * @param  array{type: string, amount: float|int|string, transacted_on: string, cash_account_id: int, party?: string|null, fund_provider_id?: int|null, remark?: string|null}  $data
     */
    public function record(Fund $fund, array $data, User $actor): FundTransaction
    {
        return DB::transaction(function () use ($fund, $data, $actor) {
            /** @var Fund $fund */
            $fund = Fund::query()->whereKey($fund->getKey())->lockForUpdate()->firstOrFail();

            if ($fund->status !== 'active') {
                throw ValidationException::withMessages(['status' => __('This fund is closed.')]);
            }
            if (Carbon::parse($data['transacted_on'])->lt($fund->opened_on)) {
                throw ValidationException::withMessages(['transacted_on' => __('The date is before the fund was started.')]);
            }

            $amount = round((float) $data['amount'], $fund->currency === 'KHR' ? 0 : 2);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => __('The amount must be greater than zero.')]);
            }

            $entry = new FundTransaction([
                'fund_id' => $fund->id,
                'type' => $data['type'],
                'amount' => $amount,
                'transacted_on' => $data['transacted_on'],
                'party' => $data['party'] ?? null,
                'fund_provider_id' => $data['type'] === FundTransaction::IN ? ($data['fund_provider_id'] ?? null) : null,
                'remark' => $data['remark'] ?? null,
                'cash_account_id' => $data['cash_account_id'],
                'created_by' => $actor->getKey(),
            ]);

            if ($entry->type === FundTransaction::OUT) {
                $this->assertNeverOverdrawn($fund, add: $entry);
            }
            $entry->save();

            $cash = Account::query()->findOrFail($entry->cash_account_id);
            $funds = $this->fundsAccount();
            $label = trim($fund->name.' '.$fund->fund_no);
            $date = $entry->transacted_on->toDateString();
            $transaction = $entry->type === FundTransaction::OUT
                ? $this->transactions->postFund($funds, $cash, $amount, $fund->currency, $date, "Fund money out — {$label}", $entry->getMorphClass(), $entry->id, $actor)
                : $this->transactions->postFund($cash, $funds, $amount, $fund->currency, $date, "Fund money in — {$label}", $entry->getMorphClass(), $entry->id, $actor);
            $entry->forceFill(['transaction_id' => $transaction->id])->save();

            return $entry;
        });
    }

    /** Undo an entry: its ledger entry is reversed. Money in that later money out relied on cannot go. */
    public function remove(FundTransaction $entry, User $actor): Fund
    {
        return DB::transaction(function () use ($entry, $actor) {
            $fund = Fund::query()->whereKey($entry->fund_id)->lockForUpdate()->firstOrFail();

            if ($entry->type === FundTransaction::IN) {
                $this->assertNeverOverdrawn($fund, without: $entry);
            }

            if ($entry->transaction_id) {
                $transaction = FinancialTransaction::query()->find($entry->transaction_id);
                if ($transaction && $transaction->status === TransactionStatus::POSTED) {
                    $this->transactions->reverse($transaction, TransactionType::FUND, $actor);
                }
            }
            $entry->delete();

            return $fund;
        });
    }

    public function balance(Fund $fund): float
    {
        return round($fund->entries()->get()->sum(fn (FundTransaction $e) => $e->signedAmount()), 2);
    }

    /**
     * Entries oldest first, each with the balance after it.
     *
     * @return list<array{entry: FundTransaction, balance: float}>
     */
    public function statement(Fund $fund): array
    {
        $balance = 0.0;
        $rows = [];
        foreach ($fund->entries()->with(['cashAccount', 'creator'])->orderBy('transacted_on')->orderBy('id')->get() as $entry) {
            $balance = round($balance + $entry->signedAmount(), 2);
            $rows[] = ['entry' => $entry, 'balance' => $balance];
        }

        return $rows;
    }

    public function fundsAccount(): Account
    {
        $spec = self::FUNDS_ACCOUNT;
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

    /**
     * Walk the entries in date order (with one added or one left out) and
     * refuse if the balance would drop below zero on any day.
     */
    private function assertNeverOverdrawn(Fund $fund, ?FundTransaction $add = null, ?FundTransaction $without = null): void
    {
        $entries = $fund->entries()->get()
            ->reject(fn (FundTransaction $e) => $without !== null && $e->id === $without->id)
            ->values();
        if ($add !== null) {
            $entries->push($add);
        }

        // Same day: money in counts before money out.
        $sorted = $entries->sort(function (FundTransaction $a, FundTransaction $b) {
            return [$a->transacted_on->toDateString(), $a->type === FundTransaction::OUT ? 1 : 0, $a->id ?? PHP_INT_MAX]
                <=> [$b->transacted_on->toDateString(), $b->type === FundTransaction::OUT ? 1 : 0, $b->id ?? PHP_INT_MAX];
        });

        $decimals = $fund->currency === 'KHR' ? 0 : 2;
        $balance = 0.0;
        foreach ($sorted as $entry) {
            $balance = round($balance + $entry->signedAmount(), 2);
            if ($balance < -0.001) {
                $message = $add !== null
                    ? __('Not enough money in the fund: the balance would go below zero on :date (short by :amount).', ['date' => $entry->transacted_on->format('d-m-Y'), 'amount' => number_format(-$balance, $decimals)])
                    : __('This money in is needed for later money out, so it cannot be removed.');
                throw ValidationException::withMessages([$add !== null ? 'amount' : 'entry' => $message]);
            }
        }
    }
}
