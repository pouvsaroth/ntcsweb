<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\FinancialTransaction;
use App\Models\Payment;
use App\Support\Accounting\AccountType;
use App\Support\Accounting\TransactionType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Every figure here is a SQL SUM/GROUP BY — never a PHP loop over raw
 * transaction rows — so this stays cheap regardless of how many transactions
 * accumulate over the school's lifetime. Reporting reads Account balances by
 * summing (debits, credits) directly off `financial_transactions`; nothing
 * is cached or denormalized, so a reversal is reflected the instant it's
 * posted, with no separate "recompute the balance" step ever needed.
 */
final class AccountingReportService
{
    /** Net movement into (debit) minus out of (credit) a set of accounts — the "natural" reading is applied by the caller via Account::normalBalanceSign(). */
    public function netDebit(array $accountIds, ?string $dateFrom = null, ?string $dateTo = null, array $types = []): float
    {
        if ($accountIds === []) {
            return 0.0;
        }

        $debit = $this->sum('debit_account_id', $accountIds, $dateFrom, $dateTo, $types);
        $credit = $this->sum('credit_account_id', $accountIds, $dateFrom, $dateTo, $types);

        // Avoids a cosmetic "-0.00" in reports when a fully-reversed set of
        // transactions nets to exactly zero via floating-point subtraction.
        $net = round($debit - $credit, 2);

        return $net === 0.0 ? 0.0 : $net;
    }

    /**
     * Revenue/Expense grouped by account — the data behind both the Revenue
     * Report and the Profit & Loss breakdown.
     *
     * @return list<array{account: Account, amount: float}>
     */
    public function totalsByAccountType(string $accountType, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $accounts = Account::query()->where('type', $accountType)->get()->keyBy('id');

        if ($accounts->isEmpty()) {
            return [];
        }

        $sign = $accounts->first()->normalBalanceSign();

        $debits = $this->groupedSum('debit_account_id', $accounts->keys()->all(), $dateFrom, $dateTo);
        $credits = $this->groupedSum('credit_account_id', $accounts->keys()->all(), $dateFrom, $dateTo);

        return $accounts
            ->map(function (Account $account) use ($debits, $credits, $sign) {
                $amount = $sign * (($debits[$account->id] ?? 0) - ($credits[$account->id] ?? 0));

                return ['account' => $account, 'amount' => round($amount, 2)];
            })
            ->filter(fn (array $row) => $row['amount'] != 0.0)
            ->sortByDesc('amount')
            ->values()
            ->all();
    }

    public function totalRevenue(?string $dateFrom = null, ?string $dateTo = null): float
    {
        return $this->totalForType(AccountType::REVENUE, $dateFrom, $dateTo);
    }

    public function totalExpenses(?string $dateFrom = null, ?string $dateTo = null): float
    {
        return $this->totalForType(AccountType::EXPENSE, $dateFrom, $dateTo);
    }

    /**
     * Combined balance of every Cash/Bank account, as of the end of
     * `asOfDate` (or all-time if null) — used for both the dashboard tile
     * and Cash Flow's opening/closing balances.
     */
    public function cashBalance(?string $asOfDate = null): float
    {
        $ids = Account::query()->bankOrCash()->pluck('id')->all();

        return $this->netDebit($ids, null, $asOfDate);
    }

    /**
     * @return array{opening: float, student_payments: float, other_income: float, expenses: float, closing: float}
     */
    public function cashFlow(string $dateFrom, string $dateTo): array
    {
        $bankIds = Account::query()->bankOrCash()->pluck('id')->all();

        $opening = $this->netDebit($bankIds, null, $this->dayBefore($dateFrom));

        $studentPayments = (float) FinancialTransaction::query()
            ->whereIn('debit_account_id', $bankIds)
            ->where('type', TransactionType::INCOME)
            ->where('reference_type', Payment::class)
            ->whereBetween('transaction_date', [$dateFrom, $dateTo])
            ->sum('amount');

        $otherIncome = (float) FinancialTransaction::query()
            ->whereIn('debit_account_id', $bankIds)
            ->where('type', TransactionType::INCOME)
            ->whereNull('reference_type')
            ->whereBetween('transaction_date', [$dateFrom, $dateTo])
            ->sum('amount');

        // A cancelled/refunded payment credits cash back out — netted against
        // Student Payments (what it reverses); any leftover beyond that (an
        // edge case: reversing a manual "other income" entry) comes out of
        // Other Income instead. Neither bucket goes below zero.
        $reversedIncome = (float) FinancialTransaction::query()
            ->whereIn('credit_account_id', $bankIds)
            ->whereIn('type', [TransactionType::REFUND, TransactionType::ADJUSTMENT])
            ->whereBetween('transaction_date', [$dateFrom, $dateTo])
            ->sum('amount');

        $netStudentPayments = round($studentPayments - min($reversedIncome, $studentPayments), 2);
        $leftoverReversal = max(0.0, $reversedIncome - $studentPayments);
        $netOtherIncome = round(max(0.0, $otherIncome - $leftoverReversal), 2);

        $expenses = round((float) FinancialTransaction::query()
            ->whereIn('credit_account_id', $bankIds)
            ->where('type', TransactionType::EXPENSE)
            ->whereBetween('transaction_date', [$dateFrom, $dateTo])
            ->sum('amount'), 2);

        return [
            'opening' => $opening,
            'student_payments' => $netStudentPayments,
            'other_income' => $netOtherIncome,
            'expenses' => $expenses,
            'closing' => round($opening + $netStudentPayments + $netOtherIncome - $expenses, 2),
        ];
    }

    private function totalForType(string $accountType, ?string $dateFrom, ?string $dateTo): float
    {
        $ids = Account::query()->where('type', $accountType)->pluck('id')->all();

        if ($ids === []) {
            return 0.0;
        }

        $sign = AccountType::isDebitNormal($accountType) ? 1 : -1;

        return $sign * $this->netDebit($ids, $dateFrom, $dateTo);
    }

    private function sum(string $column, array $accountIds, ?string $dateFrom, ?string $dateTo, array $types): float
    {
        $query = FinancialTransaction::query()->whereIn($column, $accountIds);

        if ($dateFrom !== null) {
            $query->whereDate('transaction_date', '>=', $dateFrom);
        }

        if ($dateTo !== null) {
            $query->whereDate('transaction_date', '<=', $dateTo);
        }

        if ($types !== []) {
            $query->whereIn('type', $types);
        }

        return (float) $query->sum('amount');
    }

    /**
     * @return array<int, float> account_id => summed amount
     */
    private function groupedSum(string $column, array $accountIds, ?string $dateFrom, ?string $dateTo): array
    {
        $query = FinancialTransaction::query()
            ->select($column, DB::raw('SUM(amount) as total'))
            ->whereIn($column, $accountIds)
            ->groupBy($column);

        if ($dateFrom !== null) {
            $query->whereDate('transaction_date', '>=', $dateFrom);
        }

        if ($dateTo !== null) {
            $query->whereDate('transaction_date', '<=', $dateTo);
        }

        return $query->pluck('total', $column)->map(fn ($v) => (float) $v)->all();
    }

    private function dayBefore(string $date): string
    {
        return Carbon::parse($date)->subDay()->toDateString();
    }
}
