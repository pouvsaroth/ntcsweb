<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Platform\Billing\Payments;
use App\Platform\Billing\PlatformReceiptPdf;
use App\Platform\Models\SchoolPayment;
use App\Platform\Support\PlatformAudit;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Payments schools made to the platform: list, record, void, receipt (stage 5b). */
final class PaymentController extends Controller
{
    public function __construct(
        private readonly Payments $payments,
        private readonly PlatformAudit $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = SchoolPayment::query()
            ->with(['tenant:id,name,slug', 'subscription.plan:id,name', 'recorder:id,name', 'voider:id,name'])
            ->when($request->input('filter.tenant_id'), fn ($q, $id) => $q->where('tenant_id', (int) $id))
            ->when($request->input('filter.from'), fn ($q, $d) => $q->whereDate('paid_on', '>=', $d))
            ->when($request->input('filter.to'), fn ($q, $d) => $q->whereDate('paid_on', '<=', $d))
            ->when($request->input('filter.void') === '0', fn ($q) => $q->whereNull('voided_at'));

        $page = ApiQuery::for($query, $request)
            ->searchable('receipt_number', 'reference')
            ->filterable(['method'])
            ->sortable(['paid_on', 'receipt_number', 'amount', 'created_at'], default: '-id')
            ->paginate();

        $page->through(fn (SchoolPayment $p) => Payments::present($p));

        return ApiResponse::success($page);
    }

    /** What the Record payment dialog needs: the plan it pays for and the default amount. */
    public function quote(Request $request, Tenant $school): JsonResponse
    {
        $periods = max(1, min(120, (int) $request->input('periods', 1)));
        $sub = $this->payments->payable($school);
        abort_if($sub === null, 404, __('This school is not on a plan. Put it on a plan first.'));

        [$from, $to] = $this->payments->coverage($sub, $periods, \App\Platform\Models\SchoolSubscription::today());

        return ApiResponse::success([
            'plan' => $sub->plan?->name,
            'status' => $sub->status,
            'currency' => $sub->plan?->currency,
            'amount' => number_format((float) $sub->plan?->price * $periods, 2, '.', ''),
            'covered_from' => $from?->toDateString(),
            'covered_to' => $to?->toDateString(),
        ]);
    }

    public function store(Request $request, Tenant $school): JsonResponse
    {
        $data = $request->validate([
            'periods' => ['required', 'integer', 'between:1,120'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'method' => ['required', Rule::in(SchoolPayment::METHODS)],
            'reference' => ['nullable', 'string', 'max:100'],
            'paid_on' => ['nullable', 'date', 'before_or_equal:tomorrow'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $payment = $this->payments->record($school, $data, $request->user('staff'));

        return ApiResponse::created(Payments::present($payment));
    }

    public function void(Request $request, SchoolPayment $payment): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return ApiResponse::success(Payments::present($this->payments->void($payment, $data['reason'], $request->user('staff'))));
    }

    public function receipt(SchoolPayment $payment, PlatformReceiptPdf $pdf): Response
    {
        $this->audit->record('billing.receipt_downloaded', $payment, new: ['receipt_number' => $payment->receipt_number], tenantId: $payment->tenant_id);

        return response($pdf->render($payment), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->filename($payment).'"',
        ]);
    }
}
