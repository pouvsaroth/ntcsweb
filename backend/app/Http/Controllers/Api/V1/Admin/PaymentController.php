<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ClosePaymentRequest;
use App\Http\Requests\Api\V1\Admin\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Billing\PaymentService;
use App\Services\Billing\ReceiptPdfService;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;

final class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    /** Top-level listing — for Payment History / reports, filterable independently of any one invoice. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        $query = Payment::query()->with(['invoice', 'receivedBy']);

        if ($request->filled('date_from')) {
            $query->whereDate('payment_date', '>=', $request->string('date_from')->toString());
        }

        if ($request->filled('date_to')) {
            $query->whereDate('payment_date', '<=', $request->string('date_to')->toString());
        }

        $payments = ApiQuery::for($query, $request)
            ->searchable('payment_number', 'reference_number')
            ->filterable(['status', 'payment_method', 'invoice_id', 'student_id'])
            ->sortable(['payment_number', 'payment_date', 'amount', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(PaymentResource::collection($payments));
    }

    public function store(StorePaymentRequest $request, Invoice $invoice): JsonResponse
    {
        $payment = $this->payments->record($invoice, $request->validated(), $request->user());

        return ApiResponse::created(new PaymentResource($payment->load('invoice')));
    }

    public function show(Payment $payment): JsonResponse
    {
        $this->authorize('view', $payment);

        return ApiResponse::success(new PaymentResource($payment->load(['invoice', 'receivedBy', 'cancelledBy'])));
    }

    public function cancel(ClosePaymentRequest $request, Payment $payment): JsonResponse
    {
        $payment = $this->payments->cancel($payment, $request->validated('reason'), $request->user());

        return ApiResponse::success(new PaymentResource($payment));
    }

    public function refund(ClosePaymentRequest $request, Payment $payment): JsonResponse
    {
        $payment = $this->payments->refund($payment, $request->validated('reason'), $request->user());

        return ApiResponse::success(new PaymentResource($payment));
    }

    public function receipt(Payment $payment, ReceiptPdfService $pdf): HttpResponse
    {
        $this->authorize('view', $payment);

        return response($pdf->render($payment), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->filename($payment).'"',
        ]);
    }
}
