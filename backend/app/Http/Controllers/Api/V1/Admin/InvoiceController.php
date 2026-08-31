<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\CloseInvoiceRequest;
use App\Http\Requests\Api\V1\Admin\SendInvoiceRequest;
use App\Http\Requests\Api\V1\Admin\StoreInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\NotificationLogResource;
use App\Http\Responses\ApiResponse;
use App\Jobs\SendInvoiceNotificationJob;
use App\Models\Invoice;
use App\Services\Billing\InvoicePdfService;
use App\Services\Billing\InvoiceService;
use App\Support\Query\ApiQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;

final class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        $query = Invoice::query()->with('student');

        if ($request->filled('date_from')) {
            $query->whereDate('invoice_date', '>=', $request->string('date_from')->toString());
        }

        if ($request->filled('date_to')) {
            $query->whereDate('invoice_date', '<=', $request->string('date_to')->toString());
        }

        $invoices = ApiQuery::for($query, $request)
            ->searchable('invoice_number')
            ->filterable(['status', 'student_id'])
            ->sortable(['invoice_number', 'invoice_date', 'due_date', 'total', 'balance', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(InvoiceResource::collection($invoices));
    }

    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        $invoice = $this->invoices->create($request->validated(), $request->user());

        return ApiResponse::created(new InvoiceResource($invoice));
    }

    public function show(Invoice $invoice): JsonResponse
    {
        $this->authorize('view', $invoice);

        return ApiResponse::success(new InvoiceResource(
            $invoice->load(['student', 'items.product', 'items.variant', 'payments.receivedBy', 'createdBy', 'cancelledBy'])
        ));
    }

    public function cancel(CloseInvoiceRequest $request, Invoice $invoice): JsonResponse
    {
        $invoice = $this->invoices->cancel($invoice, $request->validated('reason'), $request->user());

        return ApiResponse::success(new InvoiceResource($invoice));
    }

    public function void(CloseInvoiceRequest $request, Invoice $invoice): JsonResponse
    {
        $invoice = $this->invoices->void($invoice, $request->validated('reason'), $request->user());

        return ApiResponse::success(new InvoiceResource($invoice));
    }

    public function downloadPdf(Invoice $invoice, InvoicePdfService $pdf): HttpResponse
    {
        $this->authorize('view', $invoice);

        return response($pdf->render($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->filename($invoice).'"',
        ]);
    }

    /**
     * Queued (see SendInvoiceNotificationJob) — the request returns as soon
     * as the send is scheduled, not once Telegram/the mail server actually
     * responds. Every call creates a brand-new NotificationLog row, so
     * "Send" and "Resend" are the same action, on purpose (see
     * InvoiceNotificationService's docblock).
     */
    public function send(SendInvoiceRequest $request, Invoice $invoice): JsonResponse
    {
        // SendInvoiceRequest::authorize() already gates this on
        // notifications.send — no further check needed here.
        SendInvoiceNotificationJob::dispatch(
            $invoice->id,
            $request->validated('recipient'),
            $request->validated('channel'),
            $this->context->idOrFail(),
            $request->user()->id,
        );

        return ApiResponse::success(message: 'Invoice queued for sending.');
    }

    public function notifications(Invoice $invoice): JsonResponse
    {
        $this->authorize('view', $invoice);

        return ApiResponse::success(
            NotificationLogResource::collection($invoice->notificationLogs()->with('sentBy')->latest()->get())
        );
    }
}
