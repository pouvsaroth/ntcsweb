<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Account;
use App\Models\Enrollment;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Accounting\AccountingReportService;
use App\Support\Accounting\AccountType;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Every figure is a single aggregate query — see AccountingReportService's
 * own docblock for why this stays cheap regardless of ledger size. Mirrors
 * BillingDashboardController's shape exactly, one level up (Accounting sits
 * on top of Billing, not beside it).
 */
final class AccountingDashboardController extends Controller
{
    public function __construct(
        private readonly AccountingReportService $reports,
        private readonly TenantContext $tenantContext,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission(Permissions::ACCOUNTING_DASHBOARD_VIEW), 403);

        $today = now()->toDateString();
        $dateFrom = $request->string('date_from')->toString() ?: null;
        $dateTo = $request->string('date_to')->toString() ?: null;

        $revenue = $this->reports->totalRevenue($dateFrom, $dateTo);
        $expenses = $this->reports->totalExpenses($dateFrom, $dateTo);

        return ApiResponse::success([
            // Every figure below is already converted to this currency — see
            // AccountingReportService's own docblock.
            'currency' => $this->tenantContext->getOrFail()->default_currency,
            'total_revenue' => $revenue,
            'total_expenses' => $expenses,
            'net_profit' => round($revenue - $expenses, 2),
            'todays_income' => $this->reports->totalRevenue($today, $today),
            'todays_expenses' => $this->reports->totalExpenses($today, $today),
            'outstanding_receivables' => (float) Invoice::query()->outstanding()->sum('balance'),
            'overdue_receivables' => (float) Invoice::query()->overdue()->sum('balance'),
            ...$this->cashByAccount(),
        ]);
    }

    /**
     * The rows behind the dashboard's income tiles — every posting that
     * touches a Revenue account in the range, oldest first. Signed the same
     * way totalRevenue() nets them (credit to revenue +, debit to revenue −,
     * i.e. a cancelled/refunded payment's reversal is negative), so the rows
     * add up to `total`, which is exactly the tile's figure. Each row keeps
     * its own currency; `total` is converted to the school's, like the tile.
     * Unpaginated: bounded by one date range (the tile only ever asks for a
     * month), not by ledger size.
     */
    public function income(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission(Permissions::ACCOUNTING_DASHBOARD_VIEW), 403);

        $validated = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
        ]);

        $revenueIds = Account::query()->where('type', AccountType::REVENUE)->pluck('id')->all();

        $transactions = FinancialTransaction::query()
            ->whereDate('transaction_date', '>=', $validated['date_from'])
            ->whereDate('transaction_date', '<=', $validated['date_to'])
            ->where(fn ($query) => $query->whereIn('credit_account_id', $revenueIds)->orWhereIn('debit_account_id', $revenueIds))
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get(['id', 'transaction_date', 'description', 'amount', 'currency', 'debit_account_id', 'credit_account_id', 'reference_type', 'reference_id']);

        // A payment's income posting and any reversal of it both reference
        // the Payment — one batched load for all of them.
        $payments = Payment::query()
            ->whereIn('id', $transactions->where('reference_type', Payment::class)->pluck('reference_id')->unique())
            ->with([
                'student',
                'invoice.items.product',
                'invoice.items.reference' => fn (MorphTo $morph) => $morph->morphWith([Enrollment::class => ['coursePackage']]),
            ])
            ->get()
            ->keyBy('id');

        $rows = $transactions
            ->map(function (FinancialTransaction $transaction) use ($revenueIds, $payments) {
                $sign = (in_array($transaction->credit_account_id, $revenueIds) ? 1 : 0)
                    - (in_array($transaction->debit_account_id, $revenueIds) ? 1 : 0);

                return $sign === 0 ? null : [
                    'id' => $transaction->id,
                    'date' => $transaction->transaction_date?->toDateString(),
                    'description' => $this->incomeDescription($transaction, $payments),
                    'amount' => $sign * (float) $transaction->amount,
                    'currency' => $transaction->currency,
                ];
            })
            ->filter()
            ->values();

        return ApiResponse::success([
            'currency' => $this->tenantContext->getOrFail()->default_currency,
            'total' => $this->reports->totalRevenue($validated['date_from'], $validated['date_to']),
            'items' => $rows,
        ]);
    }

    /**
     * "Student name — Course" for a payment (the course package for an
     * enrollment invoice, else the invoice's product names — see
     * Invoice::courseName()); the ledger's own description for anything
     * not paid by a student (manual income, adjustments).
     *
     * @param  Collection<int, Payment>  $payments
     */
    private function incomeDescription(FinancialTransaction $transaction, Collection $payments): ?string
    {
        $payment = $transaction->reference_type === Payment::class ? $payments->get($transaction->reference_id) : null;

        if ($payment === null) {
            return $transaction->description;
        }

        $parts = array_filter([$payment->student?->fullName(), $payment->invoice?->courseName()]);

        return $parts === [] ? $transaction->description : implode(' — ', $parts);
    }

    /**
     * Per-account cash/bank balances, plus the combined total — computed
     * separately from the placeholder above to keep one query per account
     * (a school realistically has a handful of these, never thousands).
     */
    private function cashByAccount(): array
    {
        $accounts = Account::query()->bankOrCash()->active()->get();

        $balances = $accounts->map(fn (Account $account) => [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'balance' => $this->reports->netDebit([$account->id]),
        ])->all();

        return [
            'cash_accounts' => $balances,
            'total_cash_balance' => round(array_sum(array_column($balances, 'balance')), 2),
        ];
    }
}
