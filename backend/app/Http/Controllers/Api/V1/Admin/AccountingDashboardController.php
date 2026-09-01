<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Account;
use App\Models\Invoice;
use App\Services\Accounting\AccountingReportService;
use App\Support\Authorization\Permissions;
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
    public function __construct(private readonly AccountingReportService $reports) {}

    public function summary(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission(Permissions::ACCOUNTING_DASHBOARD_VIEW), 403);

        $today = now()->toDateString();
        $dateFrom = $request->string('date_from')->toString() ?: null;
        $dateTo = $request->string('date_to')->toString() ?: null;

        $revenue = $this->reports->totalRevenue($dateFrom, $dateTo);
        $expenses = $this->reports->totalExpenses($dateFrom, $dateTo);

        return ApiResponse::success([
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
