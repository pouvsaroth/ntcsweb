<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Borrowing;
use App\Models\BorrowingRepayment;
use App\Models\CustomerLoanPayment;
use App\Models\Expense;
use App\Models\FinancialTransaction;
use App\Models\FundTransaction;
use App\Models\Payment;
use App\Models\SavingsTransaction;
use App\Services\Billing\CurrencyConversionService;
use App\Support\Accounting\AccountType;
use App\Support\Accounting\TransactionType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Every figure here is a SQL SUM/GROUP BY — never a PHP loop over raw
 * transaction rows — so this stays cheap regardless of how many transactions
 * accumulate over the school's lifetime. Reporting reads Account balances by
 * summing (debits, credits) directly off `financial_transactions`; nothing
 * is cached or denormalized, so a reversal is reflected the instant it's
 * posted, with no separate "recompute the balance" step ever needed.
 *
 * `financial_transactions` can hold both USD and KHR rows (see
 * CoursePackage/Invoice's own currency column) — every SUM here additionally
 * groups by `currency` and transaction date first, then folds that small,
 * bounded result set (distinct dates in range × 2 currencies, never
 * transaction volume) into one total via CurrencyConversionService, using
 * the rate in effect on each date. This is what makes a mixed-currency
 * total meaningful instead of adding a $10 row to a 40,000 KHR row.
 */
final class AccountingReportService
{
    public function __construct(
        private readonly CurrencyConversionService $currency,
        private readonly TenantContext $tenantContext,
    ) {}

    /** Net movement into (debit) minus out of (credit) a set of accounts — the "natural" reading is applied by the caller via Account::normalBalanceSign(). */
    public function netDebit(array $accountIds, ?string $dateFrom = null, ?string $dateTo = null, array $types = [], ?\Closure $scope = null): float
    {
        if ($accountIds === []) {
            return 0.0;
        }

        $debit = $this->sum('debit_account_id', $accountIds, $dateFrom, $dateTo, $types, scope: $scope);
        $credit = $this->sum('credit_account_id', $accountIds, $dateFrom, $dateTo, $types, scope: $scope);

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

    public function totalRevenue(?string $dateFrom = null, ?string $dateTo = null, bool $schoolOnly = false): float
    {
        return $this->totalForType(AccountType::REVENUE, $dateFrom, $dateTo, $schoolOnly ? self::schoolScope(...) : null);
    }

    public function totalExpenses(?string $dateFrom = null, ?string $dateTo = null, bool $schoolOnly = false): float
    {
        return $this->totalForType(AccountType::EXPENSE, $dateFrom, $dateTo, $schoolOnly ? self::schoolScope(...) : null);
    }

    /**
     * Dashboard "school only": leaves out Loan (Owe, savings, borrowings
     * tagged for someone else) and Fund postings, and expenses whose
     * Expense for is not ticked as the school. An expense with no Expense
     * for, payroll, and student payments all count as the school's.
     *
     * @param  Builder<FinancialTransaction>|\Illuminate\Database\Query\Builder  $query
     */
    public static function schoolScope($query): void
    {
        $notSchool = fn ($q) => $q->select('id')->from('expense_entities')->where('is_school', false);
        $query
            ->where(fn ($q) => $q->whereNull('reference_type')->orWhereNotIn('reference_type', [
                (new CustomerLoanPayment)->getMorphClass(),
                (new SavingsTransaction)->getMorphClass(),
                (new FundTransaction)->getMorphClass(),
                (new Borrowing)->getMorphClass(),
            ]))
            // An expense for someone other than the school.
            ->whereNot(fn ($q) => $q->where('reference_type', (new Expense)->getMorphClass())
                ->whereIn('reference_id', fn ($e) => $e->select('id')->from('expenses')->whereIn('expense_entity_id', $notSchool)))
            // A borrowing repayment for a loan borrowed for someone other than the school.
            ->whereNot(fn ($q) => $q->where('reference_type', (new BorrowingRepayment)->getMorphClass())
                ->whereIn('reference_id', fn ($r) => $r->select('borrowing_repayments.id')->from('borrowing_repayments')
                    ->join('borrowings', 'borrowings.id', '=', 'borrowing_repayments.borrowing_id')
                    ->whereIn('borrowings.expense_entity_id', $notSchool)));
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
     * @return array{opening: float, student_payments: float, other_income: float, loans_received: float, savings_deposits: float, loan_collections: float, loan_interest_received: float, expenses: float, loan_repayments: float, savings_withdrawals: float, loans_given: float, fund_in: float, fund_out: float, closing: float}
     */
    public function cashFlow(string $dateFrom, string $dateTo): array
    {
        $bankIds = Account::query()->bankOrCash()->pluck('id')->all();

        $opening = $this->netDebit($bankIds, null, $this->dayBefore($dateFrom));

        $studentPayments = $this->sum('debit_account_id', $bankIds, $dateFrom, $dateTo, [TransactionType::INCOME], referenceType: Payment::class);

        $otherIncome = $this->sum('debit_account_id', $bankIds, $dateFrom, $dateTo, [TransactionType::INCOME], referenceTypeNull: true);

        // A cancelled/refunded payment credits cash back out — netted against
        // Student Payments (what it reverses); any leftover beyond that (an
        // edge case: reversing a manual "other income" entry) comes out of
        // Other Income instead. Neither bucket goes below zero.
        $reversedIncome = $this->sum('credit_account_id', $bankIds, $dateFrom, $dateTo, [TransactionType::REFUND, TransactionType::ADJUSTMENT]);

        $netStudentPayments = round($studentPayments - min($reversedIncome, $studentPayments), 2);
        $leftoverReversal = max(0.0, $reversedIncome - $studentPayments);
        $netOtherIncome = round(max(0.0, $otherIncome - $leftoverReversal), 2);

        // A reversed expense (a removed loan repayment's interest) puts the cash back.
        $expenses = round(
            $this->sum('credit_account_id', $bankIds, $dateFrom, $dateTo, [TransactionType::EXPENSE], reversal: false)
            - $this->sum('debit_account_id', $bankIds, $dateFrom, $dateTo, [TransactionType::EXPENSE], reversal: true),
            2,
        );

        // Accounting > Borrowings: money borrowed in and principal paid back,
        // each net of its own reversals.
        $loansReceived = round(
            $this->sum('debit_account_id', $bankIds, $dateFrom, $dateTo, [TransactionType::LOAN], reversal: false)
            - $this->sum('credit_account_id', $bankIds, $dateFrom, $dateTo, [TransactionType::LOAN], reversal: true),
            2,
        );
        $loanRepayments = round(
            $this->sum('credit_account_id', $bankIds, $dateFrom, $dateTo, [TransactionType::LOAN], reversal: false)
            - $this->sum('debit_account_id', $bankIds, $dateFrom, $dateTo, [TransactionType::LOAN], reversal: true),
            2,
        );

        // Loan > Savings: customers' deposits in and withdrawals out, each net of its own reversals.
        $savingsDeposits = round(
            $this->sum('debit_account_id', $bankIds, $dateFrom, $dateTo, [TransactionType::SAVINGS], reversal: false)
            - $this->sum('credit_account_id', $bankIds, $dateFrom, $dateTo, [TransactionType::SAVINGS], reversal: true),
            2,
        );
        $savingsWithdrawals = round(
            $this->sum('credit_account_id', $bankIds, $dateFrom, $dateTo, [TransactionType::SAVINGS], reversal: false)
            - $this->sum('debit_account_id', $bankIds, $dateFrom, $dateTo, [TransactionType::SAVINGS], reversal: true),
            2,
        );

        // Loan > Owe: money lent out and principal collected back (LENDING),
        // and interest received (INCOME tied to a customer loan payment),
        // each net of its own reversals.
        $loansGiven = round(
            $this->sum('credit_account_id', $bankIds, $dateFrom, $dateTo, [TransactionType::LENDING], reversal: false)
            - $this->sum('debit_account_id', $bankIds, $dateFrom, $dateTo, [TransactionType::LENDING], reversal: true),
            2,
        );
        $loanCollections = round(
            $this->sum('debit_account_id', $bankIds, $dateFrom, $dateTo, [TransactionType::LENDING], reversal: false)
            - $this->sum('credit_account_id', $bankIds, $dateFrom, $dateTo, [TransactionType::LENDING], reversal: true),
            2,
        );
        $loanInterest = round(
            $this->sum('debit_account_id', $bankIds, $dateFrom, $dateTo, [TransactionType::INCOME], referenceType: CustomerLoanPayment::class, reversal: false)
            - $this->sum('credit_account_id', $bankIds, $dateFrom, $dateTo, [TransactionType::INCOME], referenceType: CustomerLoanPayment::class, reversal: true),
            2,
        );

        // Fund > Funds: money into and out of funds, each net of its own reversals.
        $fundIn = round(
            $this->sum('debit_account_id', $bankIds, $dateFrom, $dateTo, [TransactionType::FUND], reversal: false)
            - $this->sum('credit_account_id', $bankIds, $dateFrom, $dateTo, [TransactionType::FUND], reversal: true),
            2,
        );
        $fundOut = round(
            $this->sum('credit_account_id', $bankIds, $dateFrom, $dateTo, [TransactionType::FUND], reversal: false)
            - $this->sum('debit_account_id', $bankIds, $dateFrom, $dateTo, [TransactionType::FUND], reversal: true),
            2,
        );

        return [
            'opening' => $opening,
            'student_payments' => $netStudentPayments,
            'other_income' => $netOtherIncome,
            'loans_received' => $loansReceived,
            'savings_deposits' => $savingsDeposits,
            'loan_collections' => $loanCollections,
            'loan_interest_received' => $loanInterest,
            'expenses' => $expenses,
            'loan_repayments' => $loanRepayments,
            'savings_withdrawals' => $savingsWithdrawals,
            'loans_given' => $loansGiven,
            'fund_in' => $fundIn,
            'fund_out' => $fundOut,
            'closing' => round(
                $opening + $netStudentPayments + $netOtherIncome + $loansReceived + $savingsDeposits + $loanCollections + $loanInterest + $fundIn
                - $expenses - $loanRepayments - $savingsWithdrawals - $loansGiven - $fundOut,
                2,
            ),
        ];
    }

    private function totalForType(string $accountType, ?string $dateFrom, ?string $dateTo, ?\Closure $scope = null): float
    {
        $ids = Account::query()->where('type', $accountType)->pluck('id')->all();

        if ($ids === []) {
            return 0.0;
        }

        $sign = AccountType::isDebitNormal($accountType) ? 1 : -1;

        // + 0.0 turns -1 * 0.0 (negative zero) into plain 0.0 — otherwise a
        // day with no revenue is sent as -0.0 and shows as "-0" on screen.
        return $sign * $this->netDebit($ids, $dateFrom, $dateTo, scope: $scope) + 0.0;
    }

    /**
     * @param  list<string>  $types
     */
    private function sum(
        string $column,
        array $accountIds,
        ?string $dateFrom,
        ?string $dateTo,
        array $types,
        ?string $referenceType = null,
        bool $referenceTypeNull = false,
        ?bool $reversal = null,
        ?\Closure $scope = null,
    ): float {
        $query = FinancialTransaction::query()
            ->select('currency', DB::raw('DATE(transaction_date) as tx_date'), DB::raw('SUM(amount) as total'))
            ->whereIn($column, $accountIds)
            ->groupBy('currency', DB::raw('DATE(transaction_date)'));

        if ($scope !== null) {
            $scope($query);
        }

        if ($dateFrom !== null) {
            $query->whereDate('transaction_date', '>=', $dateFrom);
        }

        if ($dateTo !== null) {
            $query->whereDate('transaction_date', '<=', $dateTo);
        }

        if ($types !== []) {
            $query->whereIn('type', $types);
        }

        if ($referenceType !== null) {
            $query->where('reference_type', $referenceType);
        }

        if ($referenceTypeNull) {
            $query->whereNull('reference_type');
        }

        // true: only reversal entries; false: only originals; null: both.
        if ($reversal === true) {
            $query->whereNotNull('reverses_transaction_id');
        } elseif ($reversal === false) {
            $query->whereNull('reverses_transaction_id');
        }

        return $this->currency->sumConverted($query->get(), $this->tenantContext->getOrFail());
    }

    /**
     * @return array<int, float> account_id => summed amount, converted to the tenant's default currency
     */
    private function groupedSum(string $column, array $accountIds, ?string $dateFrom, ?string $dateTo): array
    {
        $query = FinancialTransaction::query()
            ->select($column, 'currency', DB::raw('DATE(transaction_date) as tx_date'), DB::raw('SUM(amount) as total'))
            ->whereIn($column, $accountIds)
            ->groupBy($column, 'currency', DB::raw('DATE(transaction_date)'));

        if ($dateFrom !== null) {
            $query->whereDate('transaction_date', '>=', $dateFrom);
        }

        if ($dateTo !== null) {
            $query->whereDate('transaction_date', '<=', $dateTo);
        }

        $tenant = $this->tenantContext->getOrFail();
        $result = [];

        foreach ($query->get() as $row) {
            $rate = $this->currency->rateForDate($row->tx_date);
            $converted = $this->currency->convert((float) $row->total, $row->currency, CurrencyConversionService::target($tenant), $rate);
            $result[$row->{$column}] = ($result[$row->{$column}] ?? 0.0) + $converted;
        }

        return $result;
    }

    private function dayBefore(string $date): string
    {
        return Carbon::parse($date)->subDay()->toDateString();
    }
}
