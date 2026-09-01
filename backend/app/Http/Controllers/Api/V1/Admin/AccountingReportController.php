<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Accounting\AccountingReportService;
use App\Support\Accounting\AccountType;
use App\Support\Authorization\Permissions;
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
    public function __construct(private readonly AccountingReportService $reports) {}

    public function revenue(Request $request): JsonResponse|HttpResponse
    {
        $this->authorizeReports($request);
        [$dateFrom, $dateTo] = $this->range($request);

        $rows = $this->reports->totalsByAccountType(AccountType::REVENUE, $dateFrom, $dateTo);

        if ($request->string('format')->toString() === 'csv') {
            return $this->csv('revenue-report', ['Account Code', 'Account Name', 'Amount'], collect($rows)->map(
                fn (array $row) => [$row['account']->code, $row['account']->name, number_format($row['amount'], 2, '.', '')]
            ));
        }

        return ApiResponse::success([
            'lines' => collect($rows)->map(fn (array $row) => [
                'account_id' => $row['account']->id,
                'account_code' => $row['account']->code,
                'account_name' => $row['account']->name,
                'amount' => $row['amount'],
            ]),
            'total' => round(collect($rows)->sum('amount'), 2),
        ]);
    }

    public function expenses(Request $request): JsonResponse|HttpResponse
    {
        $this->authorizeReports($request);
        [$dateFrom, $dateTo] = $this->range($request);

        $rows = $this->reports->totalsByAccountType(AccountType::EXPENSE, $dateFrom, $dateTo);

        if ($request->string('format')->toString() === 'csv') {
            return $this->csv('expense-report', ['Account Code', 'Account Name', 'Amount'], collect($rows)->map(
                fn (array $row) => [$row['account']->code, $row['account']->name, number_format($row['amount'], 2, '.', '')]
            ));
        }

        return ApiResponse::success([
            'lines' => collect($rows)->map(fn (array $row) => [
                'account_id' => $row['account']->id,
                'account_code' => $row['account']->code,
                'account_name' => $row['account']->name,
                'amount' => $row['amount'],
            ]),
            'total' => round(collect($rows)->sum('amount'), 2),
        ]);
    }

    public function profitLoss(Request $request): JsonResponse
    {
        $this->authorizeReports($request);
        [$dateFrom, $dateTo] = $this->range($request);

        $revenueLines = $this->reports->totalsByAccountType(AccountType::REVENUE, $dateFrom, $dateTo);
        $expenseLines = $this->reports->totalsByAccountType(AccountType::EXPENSE, $dateFrom, $dateTo);

        $totalRevenue = round(collect($revenueLines)->sum('amount'), 2);
        $totalExpenses = round(collect($expenseLines)->sum('amount'), 2);
        $net = round($totalRevenue - $totalExpenses, 2);

        return ApiResponse::success([
            'revenue' => [
                'lines' => collect($revenueLines)->map(fn (array $row) => ['account_name' => $row['account']->auditDisplayName(), 'amount' => $row['amount']]),
                'total' => $totalRevenue,
            ],
            'expenses' => [
                'lines' => collect($expenseLines)->map(fn (array $row) => ['account_name' => $row['account']->auditDisplayName(), 'amount' => $row['amount']]),
                'total' => $totalExpenses,
            ],
            'net_profit' => $net,
            'is_profit' => $net >= 0,
        ]);
    }

    public function cashFlow(Request $request): JsonResponse
    {
        $this->authorizeReports($request);
        [$dateFrom, $dateTo] = $this->range($request);

        $dateFrom ??= now()->startOfMonth()->toDateString();
        $dateTo ??= now()->toDateString();

        return ApiResponse::success($this->reports->cashFlow($dateFrom, $dateTo));
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
