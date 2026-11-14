<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Account;
use App\Models\Tenant;
use App\Services\Accounting\AccountingReportService;
use App\Services\Billing\CurrencyConversionService;
use App\Support\Accounting\AccountType;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;

/**
 * Revenue/Expense/Profit&Loss/Cash Flow — every figure comes from
 * AccountingReportService's own SQL aggregation, never a PHP loop over raw
 * transactions. `?format=csv` on the two grouped reports exports exactly
 * what the UI shows, respecting the same filters and permission — no new
 * export library, just plain `fputcsv` (the project doesn't have one yet
 * for anything beyond PDF, which doesn't fit a tabular report as well).
 */
final class AccountingReportController extends Controller
{
    public function __construct(
        private readonly AccountingReportService $reports,
        private readonly TenantContext $tenantContext,
    ) {}

    public function revenue(Request $request): JsonResponse|HttpResponse
    {
        return $this->accountReport($request, AccountType::REVENUE, 'revenue-report');
    }

    public function expenses(Request $request): JsonResponse|HttpResponse
    {
        return $this->accountReport($request, AccountType::EXPENSE, 'expense-report');
    }

    private function accountReport(Request $request, string $accountType, string $filename): JsonResponse|HttpResponse
    {
        $this->authorizeReports($request);
        [$dateFrom, $dateTo] = $this->range($request);

        $report = $this->grouped($accountType, $dateFrom, $dateTo);

        if ($request->string('format')->toString() === 'csv') {
            return $this->csv($filename, ['Account Code', 'Account Name', 'Amount (USD)', 'Amount (KHR)'], $report['lines']->map(
                fn (array $row) => [$row['account']->code, $row['account']->name, number_format($row['amount_usd'], 2, '.', ''), number_format($row['amount_khr'], 0, '.', '')]
            ));
        }

        return ApiResponse::success([
            'lines' => $report['lines']->map(fn (array $row) => [
                'account_id' => $row['account']->id,
                'account_code' => $row['account']->code,
                'account_name' => $row['account']->name,
                'amount' => $row['amount'],
                'amount_usd' => $row['amount_usd'],
                'amount_khr' => $row['amount_khr'],
            ])->values(),
            'total' => $report['total'],
            'total_usd' => $report['total_usd'],
            'total_khr' => $report['total_khr'],
        ]);
    }

    public function profitLoss(Request $request): JsonResponse
    {
        $this->authorizeReports($request);
        [$dateFrom, $dateTo] = $this->range($request);

        $revenue = $this->grouped(AccountType::REVENUE, $dateFrom, $dateTo);
        $expenses = $this->grouped(AccountType::EXPENSE, $dateFrom, $dateTo);
        $section = fn (array $report) => [
            'lines' => $report['lines']->map(fn (array $row) => [
                'account_name' => $row['account']->auditDisplayName(),
                'amount' => $row['amount'],
                'amount_usd' => $row['amount_usd'],
                'amount_khr' => $row['amount_khr'],
            ])->values(),
            'total' => $report['total'],
            'total_usd' => $report['total_usd'],
            'total_khr' => $report['total_khr'],
        ];
        $net = round($revenue['total'] - $expenses['total'], 2);

        return ApiResponse::success([
            'revenue' => $section($revenue),
            'expenses' => $section($expenses),
            'net_profit' => $net,
            'net_profit_usd' => round($revenue['total_usd'] - $expenses['total_usd'], 2),
            'net_profit_khr' => round($revenue['total_khr'] - $expenses['total_khr']),
            'is_profit' => $net >= 0,
        ]);
    }

    public function cashFlow(Request $request): JsonResponse
    {
        $this->authorizeReports($request);
        [$dateFrom, $dateTo] = $this->range($request);

        $dateFrom ??= now()->startOfMonth()->toDateString();
        $dateTo ??= now()->toDateString();

        // Each figure in the school's currency (as before) plus `<key>_usd`
        // and `<key>_khr`, every transaction converted at its own date's rate.
        $both = CurrencyConversionService::both(fn () => $this->reports->cashFlow($dateFrom, $dateTo));
        $flow = $both[$this->schoolCurrency()];
        foreach (array_keys($flow) as $key) {
            $flow["{$key}_usd"] = $both[Tenant::CURRENCY_USD][$key];
            $flow["{$key}_khr"] = round($both[Tenant::CURRENCY_KHR][$key]);
        }

        return ApiResponse::success($flow);
    }

    /**
     * Revenue or expense by account, in the school's currency and in USD and
     * KHR. The USD and KHR runs are matched to the lines by account (each
     * run sorts by its own amounts, so their order can differ).
     *
     * @return array{lines: Collection<int, array{account: Account, amount: float, amount_usd: float, amount_khr: float}>, total: float, total_usd: float, total_khr: float}
     */
    private function grouped(string $accountType, ?string $dateFrom, ?string $dateTo): array
    {
        $both = CurrencyConversionService::both(fn () => collect($this->reports->totalsByAccountType($accountType, $dateFrom, $dateTo)));
        $usd = $both[Tenant::CURRENCY_USD]->mapWithKeys(fn (array $row) => [$row['account']->id => $row['amount']]);
        $khr = $both[Tenant::CURRENCY_KHR]->mapWithKeys(fn (array $row) => [$row['account']->id => $row['amount']]);

        $lines = $both[$this->schoolCurrency()]->map(fn (array $row) => $row + [
            'amount_usd' => (float) ($usd[$row['account']->id] ?? 0),
            'amount_khr' => round((float) ($khr[$row['account']->id] ?? 0)),
        ]);

        return [
            'lines' => $lines,
            'total' => round($lines->sum('amount'), 2),
            'total_usd' => round($usd->sum(), 2),
            'total_khr' => round($khr->sum()),
        ];
    }

    private function schoolCurrency(): string
    {
        return $this->tenantContext->getOrFail()->default_currency;
    }

    private function authorizeReports(Request $request): void
    {
        abort_unless($request->user()?->hasPermission(Permissions::REPORTS_FINANCIAL_VIEW), 403);
    }

    /** @return array{0: ?string, 1: ?string} */
    private function range(Request $request): array
    {
        return [
            $request->filled('date_from') ? $request->string('date_from')->toString() : null,
            $request->filled('date_to') ? $request->string('date_to')->toString() : null,
        ];
    }

    private function csv(string $filename, array $headers, Collection $rows): HttpResponse
    {
        abort_unless(request()->user()?->hasPermission(Permissions::REPORTS_FINANCIAL_EXPORT), 403);

        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}-".now()->toDateString().'.csv"',
        ]);
    }
}
