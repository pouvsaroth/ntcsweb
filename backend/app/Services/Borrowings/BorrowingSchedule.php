<?php

declare(strict_types=1);

namespace App\Services\Borrowings;

use App\Models\Borrowing;
use App\Models\CustomerLoan;
use Illuminate\Support\Carbon;

/**
 * The monthly repayment plan of a loan (Borrowings, or Owe: lent to a
 * customer), worked out from its terms:
 *  - none: the amount split evenly, no interest;
 *  - flat: interest on the full amount every month, the amount split evenly;
 *  - reducing: interest on what is still owed, equal monthly payments.
 * KHR rounds to whole riel, USD to cents; the last month takes the rest.
 * A loan without months to pay has no plan (flexible); without a first
 * payment date, the plan starts a month after the money was received.
 */
final class BorrowingSchedule
{
    /**
     * @return list<array{no: int, due_on: string, principal: float, interest: float, total: float, balance: float}>
     */
    public static function for(Borrowing|CustomerLoan $loan): array
    {
        // No months to pay: a flexible loan, paid any time, so no plan.
        if (! $loan->term_months) {
            return [];
        }

        return self::make(
            (float) $loan->amount,
            (string) $loan->currency,
            (string) $loan->interest_type,
            (float) $loan->interest_rate,
            (string) $loan->interest_period,
            (int) $loan->term_months,
            $loan->first_payment_on
                ? Carbon::parse($loan->first_payment_on)
                : Carbon::parse($loan instanceof CustomerLoan ? $loan->lent_on : $loan->received_on)->addMonthNoOverflow(),
        );
    }

    /**
     * @return list<array{no: int, due_on: string, principal: float, interest: float, total: float, balance: float}>
     */
    public static function make(float $amount, string $currency, string $type, float $rate, string $period, int $months, Carbon $first): array
    {
        $months = max(1, $months);
        $decimals = strtoupper($currency) === 'KHR' ? 0 : 2;
        $monthly = $type === 'none' ? 0.0 : ($period === 'year' ? $rate / 12 : $rate) / 100;

        // Reducing balance: one equal payment each month (annuity).
        $payment = $type === 'reducing' && $monthly > 0
            ? $amount * $monthly / (1 - (1 + $monthly) ** -$months)
            : null;

        $rows = [];
        $balance = round($amount, $decimals);
        for ($i = 1; $i <= $months; $i++) {
            $interest = match (true) {
                $type === 'flat' => round($amount * $monthly, $decimals),
                $type === 'reducing' => round($balance * $monthly, $decimals),
                default => 0.0,
            };
            $principal = $i === $months
                ? $balance
                : min($balance, round($payment !== null ? $payment - $interest : $amount / $months, $decimals));
            $balance = round($balance - $principal, $decimals);

            $rows[] = [
                'no' => $i,
                'due_on' => $first->copy()->addMonthsNoOverflow($i - 1)->toDateString(),
                'principal' => (float) $principal,
                'interest' => (float) $interest,
                'total' => (float) round($principal + $interest, $decimals),
                'balance' => (float) $balance,
            ];
        }

        return $rows;
    }

    /**
     * Marks each month paid once the principal paid so far covers it, late
     * when unpaid and past due. `due` is what is still owed for that month
     * (less when part of it is already paid).
     *
     * @param  list<array{no: int, due_on: string, principal: float, interest: float, total: float, balance: float}>  $rows
     * @return list<array{no: int, due_on: string, principal: float, interest: float, total: float, balance: float, paid: bool, late: bool, due_principal: float}>
     */
    public static function withPayments(array $rows, float $paidPrincipal, string $today): array
    {
        $cumulative = 0.0;

        return array_map(function (array $row) use (&$cumulative, $paidPrincipal, $today) {
            $before = $cumulative;
            $cumulative = round($cumulative + $row['principal'], 2);
            $paid = $paidPrincipal >= $cumulative - 0.001;

            return [
                ...$row,
                'paid' => $paid,
                'late' => ! $paid && $row['due_on'] < $today,
                'due_principal' => $paid ? 0.0 : round(min($row['principal'], $cumulative - max($before, $paidPrincipal)), 2),
            ];
        }, $rows);
    }

    /**
     * One month's interest on a loan as it stands: flat on the full amount,
     * reducing on what is still owed. Suggested when paying a flexible loan.
     */
    public static function monthInterest(Borrowing|CustomerLoan $loan, float $outstanding): float
    {
        if ($loan->interest_type === 'none' || $outstanding <= 0) {
            return 0.0;
        }
        $monthly = ($loan->interest_period === 'year' ? (float) $loan->interest_rate / 12 : (float) $loan->interest_rate) / 100;
        $base = $loan->interest_type === 'flat' ? (float) $loan->amount : $outstanding;

        return (float) round($base * $monthly, strtoupper((string) $loan->currency) === 'KHR' ? 0 : 2);
    }

    /**
     * @param  list<array{principal: float, interest: float, total: float}>  $rows
     * @return array{principal: float, interest: float, total: float}
     */
    public static function totals(array $rows): array
    {
        return [
            'principal' => round(array_sum(array_column($rows, 'principal')), 2),
            'interest' => round(array_sum(array_column($rows, 'interest')), 2),
            'total' => round(array_sum(array_column($rows, 'total')), 2),
        ];
    }
}
