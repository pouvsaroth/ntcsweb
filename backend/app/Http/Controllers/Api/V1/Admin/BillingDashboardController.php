<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Authorization\Permissions;
use App\Support\Billing\InvoiceStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Every figure here is a single aggregate query (COUNT/SUM/GROUP BY) — never
 * a full table scan pulled into PHP, per the class's own scale requirement.
 * `billing-reports.view` is checked directly (a bare permission slug, no
 * dedicated model/policy needed — see AuthServiceProvider's Gate::before,
 * which already answers dot-containing abilities straight from RBAC).
 */
final class BillingDashboardController extends Controller
{
    public function summary(): JsonResponse
    {
        $this->authorize(Permissions::BILLING_REPORTS_VIEW);

        $today = now()->toDateString();

        $statusCounts = Invoice::query()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return ApiResponse::success([
            'todays_sales' => (float) Invoice::query()->whereDate('invoice_date', $today)->sum('total'),
            'todays_payments' => (float) Payment::query()->completed()->whereDate('payment_date', $today)->sum('amount'),
            'outstanding' => (float) Invoice::query()->outstanding()->sum('balance'),
            'overdue' => (float) Invoice::query()->overdue()->sum('balance'),
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
     */
    public function paymentsByMethod(Request $request): JsonResponse
    {
        $this->authorize(Permissions::BILLING_REPORTS_VIEW);

        $query = Payment::query()->completed();

        if ($request->filled('date_from')) {
            $query->whereDate('payment_date', '>=', $request->string('date_from')->toString());
        }

        if ($request->filled('date_to')) {
            $query->whereDate('payment_date', '<=', $request->string('date_to')->toString());
        }

        $rows = $query->selectRaw('payment_method, count(*) as count, sum(amount) as total')
            ->groupBy('payment_method')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'payment_method' => $row->payment_method,
                'count' => (int) $row->count,
                'total' => (float) $row->total,
            ]);

        return ApiResponse::success($rows);
    }
}
