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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Loan > Reports: Savings and Owe together for a period, per currency (USD
 * and KHR are never added together). Each part is only sent to someone who
 * can see that menu; both together add a per-customer position (savings
 * kept vs money owed).
 */
final class LoanReportController extends Controller
{
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
            'savings' => $savings ? ['totals' => $savings['totals'], 'customers' => array_values($savings['customers'])] : null,
            'loans' => $loans ? ['totals' => $loans['totals'], 'customers' => array_values($loans['customers'])] : null,
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

        $customers = [];
        $totals = [];
        foreach ($rows as $row) {
            $c = $row->currency;
            $key = "{$row->customer_id}|{$c}";
            $customers[$key] = [
                'customer_id' => (int) $row->customer_id,
                'name' => $row->name,
                'code' => $row->code,
                'currency' => $c,
                'status' => $row->status,
                'deposits' => round((float) $row->deposits, 2),
                'withdrawals' => round((float) $row->withdrawals, 2),
                'balance' => round((float) $row->balance, 2),
            ];
            $totals[$c] ??= ['currency' => $c, 'accounts' => 0, 'open_accounts' => 0, 'deposits' => 0.0, 'withdrawals' => 0.0, 'balance' => 0.0];
            $totals[$c]['accounts']++;
            $totals[$c]['open_accounts'] += $row->status === 'open' ? 1 : 0;
            $totals[$c]['deposits'] += (float) $row->deposits;
            $totals[$c]['withdrawals'] += (float) $row->withdrawals;
            $totals[$c]['balance'] += (float) $row->balance;
        }
        uasort($customers, fn ($a, $b) => [$a['currency'], -$a['balance']] <=> [$b['currency'], -$b['balance']]);

        return ['totals' => $this->perCurrency($totals, ['deposits', 'withdrawals', 'balance'], ['accounts', 'open_accounts']), 'customers' => $customers];
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

        $paidInPeriod = CustomerLoanPayment::query()
            ->whereBetween('paid_on', [$from, $to])
            ->groupBy('customer_loan_id')
            ->get(['customer_loan_id', DB::raw('SUM(principal) as principal'), DB::raw('SUM(interest) as interest')])
            ->keyBy('customer_loan_id');

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
            $period = $paidInPeriod->get($loan->id);
            $principal = (float) ($period->principal ?? 0);
            $interest = (float) ($period->interest ?? 0);

            $key = "{$loan->loan_customer_id}|{$c}";
            $customers[$key] ??= ['customer_id' => $loan->loan_customer_id, 'name' => $loan->customer?->name, 'code' => $loan->customer?->code, 'currency' => $c, 'loans' => 0, 'lent' => 0.0, 'collected' => 0.0, 'interest' => 0.0, 'owed' => 0.0, 'late' => 0.0];
            $totals[$c] ??= ['currency' => $c, 'active_loans' => 0, 'late_loans' => 0, 'lent' => 0.0, 'collected' => 0.0, 'interest' => 0.0, 'owed' => 0.0, 'late' => 0.0];
            foreach ([&$customers[$key], &$totals[$c]] as &$bucket) {
                $bucket['lent'] += $lentInPeriod;
                $bucket['collected'] += $principal;
                $bucket['interest'] += $interest;
                $bucket['owed'] += $owed;
                $bucket['late'] += $late;
            }
            unset($bucket);
            $customers[$key]['loans']++;
            $totals[$c]['active_loans'] += $loan->status === 'active' ? 1 : 0;
            $totals[$c]['late_loans'] += $late > 0 ? 1 : 0;
        }

        $customers = array_map(fn (array $row) => [...$row, ...array_map(fn ($v) => round($v, 2), array_intersect_key($row, array_flip(['lent', 'collected', 'interest', 'owed', 'late'])))], $customers);
        uasort($customers, fn ($a, $b) => [$a['currency'], -$a['owed']] <=> [$b['currency'], -$b['owed']]);

        return ['totals' => $this->perCurrency($totals, ['lent', 'collected', 'interest', 'owed', 'late'], ['active_loans', 'late_loans']), 'customers' => $customers];
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
            $row = $totals[$c] ?? ['currency' => $c, ...array_fill_keys($counts, 0)];
            foreach ($money as $field) {
                $row[$field] = round((float) ($row[$field] ?? 0), 2);
            }
            $out[] = $row;
        }

        return $out;
    }
}
