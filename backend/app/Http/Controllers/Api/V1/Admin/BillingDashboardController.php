<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Billing\CurrencyConversionService;
use App\Support\Authorization\Permissions;
use App\Support\Billing\InvoiceStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Every figure here is a single aggregate query (COUNT/SUM/GROUP BY) — never
 * a full table scan pulled into PHP, per the class's own scale requirement.
 * `billing-reports.view` is checked directly (a bare permission slug, no
 * dedicated model/policy needed — see AuthServiceProvider's Gate::before,
 * which already answers dot-containing abilities straight from RBAC).
 *
 * `todays_sales`/`outstanding`/`overdue` group Invoice's own `currency`
 * column (plus `invoice_date`, for the ones not already scoped to a single
 * day) before summing; `todays_payments` has no currency column on Payment
 * itself, so it joins to the parent Invoice for it. All four fold through
 * CurrencyConversionService into the tenant's default currency — see
 * AccountingReportService's docblock for why the grouped-then-folded shape
 * stays a bounded aggregate, not a per-row scan.
 */
final class BillingDashboardController extends Controller
{
    public function __construct(
        private readonly CurrencyConversionService $currency,
        private readonly TenantContext $tenantContext,
    ) {}

    public function summary(): JsonResponse
    {
        $this->authorize(Permissions::BILLING_REPORTS_VIEW);

        $tenant = $this->tenantContext->getOrFail();
        $today = now()->toDateString();

        $statusCounts = Invoice::query()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $todaysSalesRows = Invoice::query()
            ->whereDate('invoice_date', $today)
            ->select('currency', DB::raw("'{$today}' as tx_date"), DB::raw('SUM(total) as total'))
            ->groupBy('currency')
            ->get();

        $todaysPaymentsRows = Payment::query()
            ->completed()
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->whereDate('payments.payment_date', $today)
            ->select('invoices.currency as currency', DB::raw("'{$today}' as tx_date"), DB::raw('SUM(payments.amount) as total'))
            ->groupBy('invoices.currency')
            ->get();

        $outstandingRows = Invoice::query()
            ->outstanding()
            ->select('currency', DB::raw('DATE(invoice_date) as tx_date'), DB::raw('SUM(balance) as total'))
            ->groupBy('currency', DB::raw('DATE(invoice_date)'))
            ->get();

        $overdueRows = Invoice::query()
            ->overdue()
            ->select('currency', DB::raw('DATE(invoice_date) as tx_date'), DB::raw('SUM(balance) as total'))
            ->groupBy('currency', DB::raw('DATE(invoice_date)'))
            ->get();

        return ApiResponse::success([
            // Every amount below is already converted to this currency.
            'currency' => $tenant->default_currency,
            'todays_sales' => $this->currency->sumConverted($todaysSalesRows, $tenant),
            'todays_payments' => $this->currency->sumConverted($todaysPaymentsRows, $tenant),
            'outstanding' => $this->currency->sumConverted($outstandingRows, $tenant),
            'overdue' => $this->currency->sumConverted($overdueRows, $tenant),
            'invoice_counts' => [
                'total' => (int) $statusCounts->sum(),
                'paid' => (int) ($statusCounts[InvoiceStatus::PAID] ?? 0),
                'partial' => (int) ($statusCounts[InvoiceStatus::PARTIALLY_PAID] ?? 0),
                'unpaid' => (int) ($statusCounts[InvoiceStatus::ISSUED] ?? 0),
                'overdue' => (int) ($statusCounts[InvoiceStatus::OVERDUE] ?? 0),
                'cancelled_or_void' => (int) ($statusCounts[InvoiceStatus::CANCELLED] ?? 0) + (int) ($statusCounts[InvoiceStatus::VOID] ?? 0),
            ],
        ]);
    }

    /**
     * "Outstanding Invoices"/"Overdue Invoices"/"Student Payment History"
     * reports are already just GET /invoices or GET /payments with a
     * `filter[...]` — see those controllers. This is the one report that
     * genuinely needs server-side aggregation instead of a plain filtered
     * list, so it earns its own endpoint.
     *
     * `count` doesn't need currency (a payment is a payment regardless of
     * what it's in), so it's one simple query; `total` is grouped by
     * `payment_method` + the parent invoice's `currency` + date first, then
     * folded into one converted total per method — same shape as summary()
     * above.
     */
    public function paymentsByMethod(Request $request): JsonResponse
    {
        $this->authorize(Permissions::BILLING_REPORTS_VIEW);

        $tenant = $this->tenantContext->getOrFail();

        $counts = $this->scopedPayments($request)
            ->selectRaw('payment_method, count(*) as count')
            ->groupBy('payment_method')
            ->pluck('count', 'payment_method');

        $amountRows = $this->scopedPayments($request)
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->select(
                'payments.payment_method as payment_method',
                'invoices.currency as currency',
                DB::raw('DATE(payments.payment_date) as tx_date'),
                DB::raw('SUM(payments.amount) as total'),
            )
            ->groupBy('payments.payment_method', 'invoices.currency', DB::raw('DATE(payments.payment_date)'))
            ->get()
            ->groupBy('payment_method');

        $rows = $counts->keys()
            ->map(fn (string $method) => [
                'payment_method' => $method,
                'count' => (int) $counts[$method],
                'total' => $this->currency->sumConverted($amountRows[$method] ?? [], $tenant),
            ])
            ->sortByDesc('total')
            ->values();

        return ApiResponse::success($rows);
    }

    private function scopedPayments(Request $request): Builder
    {
        $query = Payment::query()->completed();

        if ($request->filled('date_from')) {
            $query->whereDate('payment_date', '>=', $request->string('date_from')->toString());
        }

        if ($request->filled('date_to')) {
            $query->whereDate('payment_date', '<=', $request->string('date_to')->toString());
        }

        return $query;
    }
}
