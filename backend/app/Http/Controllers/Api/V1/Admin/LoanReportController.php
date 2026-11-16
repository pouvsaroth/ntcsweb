<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CustomerLoan;
use App\Models\CustomerLoanPayment;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Services\Borrowings\BorrowingSchedule;
use App\Support\Authorization\Permissions;
use App\Support\Billing\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Loan > Reports: Savings and Owe together for a period, per currency (USD
 * and KHR are never added together). Each part is only sent to someone who
 * can see that menu; both together add a per-customer position (savings
 * kept vs money owed).
 *
 * Every amount also comes as `<field>_usd` and `<field>_khr` (and `all`
 * adds both currencies together): money that moved (deposits, withdrawals,
 * lent, collected, interest) at the exchange rate on its own date; balances
 * as of now (savings balance, still owed, late) at today's rate.
 */
final class LoanReportController extends Controller
{
    private const SAVINGS_MONEY = ['deposits', 'withdrawals', 'balance'];

    private const LOAN_MONEY = ['lent', 'collected', 'interest', 'owed', 'late'];

    /** @param  array<string, float>  $row  adds `<field>_usd` / `<field>_khr` = 0 for each field */
    private static function zeroBoth(array $fields): array
    {
        return array_merge(...array_map(fn ($f) => ["{$f}_usd" => 0.0, "{$f}_khr" => 0.0], $fields));
    }

    /** Adds `$amount` (in `$currency`, converted at the rate on `$date`) to `$row[<field>_usd|_khr]`. */
    private static function addBoth(array &$row, string $field, float $amount, ?string $currency, string $date): void
    {
        if (abs($amount) < 0.000001) {
            return;
        }
        $pair = Money::pairOn($amount, $currency, $date);
        $row["{$field}_usd"] += $pair['USD'] ?? 0.0;
        $row["{$field}_khr"] += $pair['KHR'] ?? 0.0;
    }

    /** Rounds every `_usd` (2 places) and `_khr` (whole riel) value. */
    private static function roundBoth(array $row): array
    {
        foreach ($row as $key => $value) {
            if (str_ends_with($key, '_usd')) {
                $row[$key] = round((float) $value, 2);
            } elseif (str_ends_with($key, '_khr')) {
                $row[$key] = round((float) $value);
            }
        }

        return $row;
    }

    /** Both currencies added together, in USD and in KHR. */
    private static function all(array $totals, array $fields): array
    {
        $all = self::zeroBoth($fields);
        foreach ($totals as $row) {
            foreach (array_keys($all) as $key) {
                $all[$key] += (float) ($row[$key] ?? 0);
            }
        }

        return self::roundBoth($all);
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $seeSavings = $user->hasPermission(Permissions::SAVINGS_VIEW);
        $seeLoans = $user->hasPermission(Permissions::CUSTOMER_LOANS_VIEW);
        abort_unless($seeSavings || $seeLoans, 403);

        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        // Normalised to yyyy-mm-dd (they go into the SQL sums below).
        $from = Carbon::parse($data['from'] ?? now()->startOfYear())->toDateString();
        $to = Carbon::parse($data['to'] ?? now())->toDateString();

        $savings = $seeSavings ? $this->savings($from, $to) : null;
        $loans = $seeLoans ? $this->loans($from, $to) : null;

        return ApiResponse::success([
            'from' => $from,
            'to' => $to,
            'savings' => $savings ? ['totals' => $savings['totals'], 'all' => self::all($savings['totals'], self::SAVINGS_MONEY), 'customers' => array_values($savings['customers'])] : null,
            'loans' => $loans ? ['totals' => $loans['totals'], 'all' => self::all($loans['totals'], self::LOAN_MONEY), 'customers' => array_values($loans['customers'])] : null,
            'positions' => $savings && $loans ? $this->positions($savings['customers'], $loans['customers']) : null,
        ]);
    }

    /**
     * Balance now (open and closed accounts), and deposits / withdrawals in the period.
     *
     * @return array{totals: list<array<string, mixed>>, customers: array<string, array<string, mixed>>}
     */
    private function savings(string $from, string $to): array
    {
        $rows = SavingsAccount::query()
            ->join('loan_customers', 'loan_customers.id', '=', 'savings_accounts.loan_customer_id')
            ->leftJoin('savings_transactions', 'savings_transactions.savings_account_id', '=', 'savings_accounts.id')
            ->groupBy('savings_accounts.loan_customer_id', 'loan_customers.name', 'loan_customers.code', 'savings_accounts.currency', 'savings_accounts.status')
            ->get([
                'savings_accounts.loan_customer_id as customer_id',
                'loan_customers.name as name',
                'loan_customers.code as code',
                'savings_accounts.currency as currency',
                'savings_accounts.status as status',
                DB::raw("COALESCE(SUM(CASE WHEN savings_transactions.type = '".SavingsTransaction::WITHDRAWAL."' THEN -savings_transactions.amount ELSE savings_transactions.amount END), 0) as balance"),
                DB::raw("COALESCE(SUM(CASE WHEN savings_transactions.type <> '".SavingsTransaction::WITHDRAWAL."' AND savings_transactions.transacted_on BETWEEN '{$from}' AND '{$to}' THEN savings_transactions.amount ELSE 0 END), 0) as deposits"),
                DB::raw("COALESCE(SUM(CASE WHEN savings_transactions.type = '".SavingsTransaction::WITHDRAWAL."' AND savings_transactions.transacted_on BETWEEN '{$from}' AND '{$to}' THEN savings_transactions.amount ELSE 0 END), 0) as withdrawals"),
            ]);

        // Deposits / withdrawals per day, for USD and KHR at each day's rate.
        $daily = SavingsTransaction::query()
            ->join('savings_accounts', 'savings_accounts.id', '=', 'savings_transactions.savings_account_id')
            ->whereBetween('savings_transactions.transacted_on', [$from, $to])
            ->groupBy('savings_accounts.loan_customer_id', 'savings_accounts.currency', 'savings_transactions.transacted_on', 'savings_transactions.type')
            ->get(['savings_accounts.loan_customer_id as customer_id', 'savings_accounts.currency as currency', 'savings_transactions.transacted_on', 'savings_transactions.type', DB::raw('SUM(savings_transactions.amount) as total')])
            ->groupBy(fn ($d) => "{$d->customer_id}|{$d->currency}");
        $today = now()->toDateString();

        $customers = [];
        $totals = [];
        foreach ($rows as $row) {
            $c = $row->currency;
            $key = "{$row->customer_id}|{$c}";
            $both = self::zeroBoth(self::SAVINGS_MONEY);
            if (! isset($customers[$key])) {
                foreach ($daily->get($key, collect()) as $d) {
                    self::addBoth($both, $d->type === SavingsTransaction::WITHDRAWAL ? 'withdrawals' : 'deposits', (float) $d->total, $c, Carbon::parse($d->transacted_on)->toDateString());
                }
            }
            self::addBoth($both, 'balance', (float) $row->balance, $c, $today);
            $customers[$key] = [
                'customer_id' => (int) $row->customer_id,
                'name' => $row->name,
                'code' => $row->code,
                'currency' => $c,
                'status' => $row->status,
                'deposits' => round((float) $row->deposits, 2),
                'withdrawals' => round((float) $row->withdrawals, 2),
                'balance' => round((float) $row->balance, 2),
                ...self::roundBoth($both),
            ];
            $totals[$c] ??= ['currency' => $c, 'accounts' => 0, 'open_accounts' => 0, 'deposits' => 0.0, 'withdrawals' => 0.0, 'balance' => 0.0, ...self::zeroBoth(self::SAVINGS_MONEY)];
            foreach ($both as $k => $v) {
                $totals[$c][$k] += $v;
            }
            $totals[$c]['accounts']++;
            $totals[$c]['open_accounts'] += $row->status === 'open' ? 1 : 0;
            $totals[$c]['deposits'] += (float) $row->deposits;
            $totals[$c]['withdrawals'] += (float) $row->withdrawals;
            $totals[$c]['balance'] += (float) $row->balance;
        }
        uasort($customers, fn ($a, $b) => [$a['currency'], -$a['balance']] <=> [$b['currency'], -$b['balance']]);

        return ['totals' => $this->perCurrency($totals, self::SAVINGS_MONEY, ['accounts', 'open_accounts']), 'customers' => $customers];
    }

    /**
     * Lent / collected / interest in the period; still owed and late now
     * (cancelled loans left out).
     *
     * @return array{totals: list<array<string, mixed>>, customers: array<string, array<string, mixed>>}
     */
    private function loans(string $from, string $to): array
    {
        $today = now()->toDateString();
        $loans = CustomerLoan::query()->where('status', '!=', 'cancelled')->with('customer:id,name,code')->withSum('payments', 'principal')->withSum('payments', 'interest')->get();

        // Per loan and day, so collected / interest convert at each day's rate.
        $paidDaily = CustomerLoanPayment::query()
            ->whereBetween('paid_on', [$from, $to])
            ->groupBy('customer_loan_id', 'paid_on')
            ->get(['customer_loan_id', 'paid_on', DB::raw('SUM(principal) as principal'), DB::raw('SUM(interest) as interest')])
            ->groupBy('customer_loan_id');

        $customers = [];
        $totals = [];
        foreach ($loans as $loan) {
            $c = $loan->currency;
            $paid = (float) $loan->payments_sum_principal;
            $owed = $loan->status === 'active' ? (float) $loan->amount - $paid : 0.0;
            $late = 0.0;
            if ($loan->status === 'active') {
                foreach (BorrowingSchedule::withPayments(BorrowingSchedule::forOwe($loan), $paid, $today, (float) $loan->payments_sum_interest) as $row) {
                    $late += ! $row['paid'] && $row['late'] ? $row['due_principal'] + $row['interest'] : 0.0;
                }
            }
            $lentInPeriod = $loan->lent_on->toDateString() >= $from && $loan->lent_on->toDateString() <= $to ? (float) $loan->amount : 0.0;
            $days = $paidDaily->get($loan->id, collect());
            $principal = (float) $days->sum('principal');
            $interest = (float) $days->sum('interest');
            $both = self::zeroBoth(self::LOAN_MONEY);
            self::addBoth($both, 'lent', $lentInPeriod, $c, $loan->lent_on->toDateString());
            foreach ($days as $d) {
                self::addBoth($both, 'collected', (float) $d->principal, $c, Carbon::parse($d->paid_on)->toDateString());
                self::addBoth($both, 'interest', (float) $d->interest, $c, Carbon::parse($d->paid_on)->toDateString());
            }
            self::addBoth($both, 'owed', $owed, $c, $today);
            self::addBoth($both, 'late', $late, $c, $today);

            $key = "{$loan->loan_customer_id}|{$c}";
            $customers[$key] ??= ['customer_id' => $loan->loan_customer_id, 'name' => $loan->customer?->name, 'code' => $loan->customer?->code, 'currency' => $c, 'loans' => 0, 'lent' => 0.0, 'collected' => 0.0, 'interest' => 0.0, 'owed' => 0.0, 'late' => 0.0, ...self::zeroBoth(self::LOAN_MONEY)];
            $totals[$c] ??= ['currency' => $c, 'active_loans' => 0, 'late_loans' => 0, 'lent' => 0.0, 'collected' => 0.0, 'interest' => 0.0, 'owed' => 0.0, 'late' => 0.0, ...self::zeroBoth(self::LOAN_MONEY)];
            foreach ([&$customers[$key], &$totals[$c]] as &$bucket) {
                $bucket['lent'] += $lentInPeriod;
                $bucket['collected'] += $principal;
                $bucket['interest'] += $interest;
                $bucket['owed'] += $owed;
                $bucket['late'] += $late;
                foreach ($both as $k => $v) {
                    $bucket[$k] += $v;
                }
            }
            unset($bucket);
            $customers[$key]['loans']++;
            $totals[$c]['active_loans'] += $loan->status === 'active' ? 1 : 0;
            $totals[$c]['late_loans'] += $late > 0 ? 1 : 0;
        }

        $customers = array_map(fn (array $row) => self::roundBoth([...$row, ...array_map(fn ($v) => round($v, 2), array_intersect_key($row, array_flip(self::LOAN_MONEY)))]), $customers);
        uasort($customers, fn ($a, $b) => [$a['currency'], -$a['owed']] <=> [$b['currency'], -$b['owed']]);

        return ['totals' => $this->perCurrency($totals, self::LOAN_MONEY, ['active_loans', 'late_loans']), 'customers' => $customers];
    }

    /**
     * Each customer per currency: savings kept with the owner and money they
     * owe; net = owed - savings (above zero: they owe more than they keep).
     *
     * @param  array<string, array<string, mixed>>  $savings
     * @param  array<string, array<string, mixed>>  $loans
     * @return list<array<string, mixed>>
     */
    private function positions(array $savings, array $loans): array
    {
        $rows = [];
        foreach (array_unique([...array_keys($savings), ...array_keys($loans)]) as $key) {
            $s = $savings[$key] ?? null;
            $l = $loans[$key] ?? null;
            $kept = (float) ($s['balance'] ?? 0);
            $owed = (float) ($l['owed'] ?? 0);
            if (abs($kept) < 0.001 && abs($owed) < 0.001) {
                continue;
            }
            $rows[] = [
                'customer_id' => $s['customer_id'] ?? $l['customer_id'],
                'name' => $s['name'] ?? $l['name'],
                'code' => $s['code'] ?? $l['code'],
                'currency' => $s['currency'] ?? $l['currency'],
                'savings' => round($kept, 2),
                'owed' => round($owed, 2),
                'net' => round($owed - $kept, 2),
                ...self::roundBoth([
                    'savings_usd' => (float) ($s['balance_usd'] ?? 0), 'savings_khr' => (float) ($s['balance_khr'] ?? 0),
                    'owed_usd' => (float) ($l['owed_usd'] ?? 0), 'owed_khr' => (float) ($l['owed_khr'] ?? 0),
                    'net_usd' => (float) ($l['owed_usd'] ?? 0) - (float) ($s['balance_usd'] ?? 0),
                    'net_khr' => (float) ($l['owed_khr'] ?? 0) - (float) ($s['balance_khr'] ?? 0),
                ]),
            ];
        }
        usort($rows, fn ($a, $b) => [$a['currency'], $a['name']] <=> [$b['currency'], $b['name']]);

        return $rows;
    }

    /**
     * USD then KHR, both always present, amounts rounded.
     *
     * @param  array<string, array<string, mixed>>  $totals
     * @param  list<string>  $money
     * @param  list<string>  $counts
     * @return list<array<string, mixed>>
     */
    private function perCurrency(array $totals, array $money, array $counts): array
    {
        $out = [];
        foreach (['USD', 'KHR'] as $c) {
            $row = $totals[$c] ?? ['currency' => $c, ...array_fill_keys($counts, 0), ...self::zeroBoth($money)];
            foreach ($money as $field) {
                $row[$field] = round((float) ($row[$field] ?? 0), 2);
            }
            $out[] = self::roundBoth($row);
        }

        return $out;
    }
}
