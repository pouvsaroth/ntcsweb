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
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Services\Billing\InvoicePdfService;
use App\Services\Billing\InvoiceService;
use App\Support\Query\ApiQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
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

        $query = Invoice::query()->with([
            'student',
            // For the list's Course column — see InvoiceResource::courseName().
            'items.product',
            'items.reference' => fn (MorphTo $morph) => $morph->morphWith([Enrollment::class => ['coursePackage']]),
        ]);

        if ($request->filled('date_from')) {
            $query->whereDate('invoice_date', '>=', $request->string('date_from')->toString());
        }

        if ($request->filled('date_to')) {
            $query->whereDate('invoice_date', '<=', $request->string('date_to')->toString());
        }

        $this->applySearch($query, trim($request->string('search')->toString()));

        // No ->searchable(): the search spans relations (student, course),
        // which ApiQuery's plain-column search can't — see applySearch().
        $invoices = ApiQuery::for($query, $request)
            ->filterable(['status', 'student_id', 'payment_type'])
            ->sortable(['invoice_number', 'invoice_date', 'due_date', 'total', 'balance', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(InvoiceResource::collection($invoices));
    }

    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        $invoice = $this->invoices->create($request->validated(), $request->user());

        return ApiResponse::created(new InvoiceResource($invoice));
    }

    /**
     * One search box over everything the Invoices list shows: invoice
     * number, student (name either order, English name, code, phone), course
     * (package, product, item description), payment type, status, currency,
     * amounts (typed with or without thousands separators), dates as
     * displayed (dd-mm-yyyy), plus notes and discount reason.
     *
     * @param  Builder<Invoice>  $query
     */
    private function applySearch(Builder $query, string $term): void
    {
        if ($term === '') {
            return;
        }

        // Escape LIKE wildcards — same reason as ApiQuery::applySearch().
        $like = '%'.addcslashes($term, '%_\\').'%';
        // "Partially paid" / "partially_paid" both reach PARTIALLY_PAID.
        $codeLike = '%'.addcslashes(str_replace(' ', '_', $term), '%_\\').'%';
        $number = preg_replace('/[^0-9.]/', '', $term);

        $query->where(function (Builder $inner) use ($like, $codeLike, $number) {
            $inner->where('invoice_number', 'ILIKE', $like)
                ->orWhere('status', 'ILIKE', $codeLike)
                ->orWhere('payment_type', 'ILIKE', $codeLike)
                ->orWhere('currency', 'ILIKE', $like)
                ->orWhere('notes', 'ILIKE', $like)
                ->orWhere('discount_reason', 'ILIKE', $like)
                ->orWhereRaw("to_char(invoice_date, 'DD-MM-YYYY') ILIKE ?", [$like])
                ->orWhereRaw("to_char(due_date, 'DD-MM-YYYY') ILIKE ?", [$like])
                ->orWhereRaw("to_char(created_at, 'DD-MM-YYYY') ILIKE ?", [$like])
                ->orWhereHas('student', fn (Builder $student) => $student
                    ->where('student_code', 'ILIKE', $like)
                    ->orWhere('english_name', 'ILIKE', $like)
                    ->orWhere('phone', 'ILIKE', $like)
                    ->orWhereRaw("concat_ws(' ', last_name, first_name) ILIKE ?", [$like])
                    ->orWhereRaw("concat_ws(' ', first_name, last_name) ILIKE ?", [$like]))
                ->orWhereHas('items', fn (Builder $item) => $item
                    ->where('description', 'ILIKE', $like)
                    ->orWhereHas('product', fn (Builder $product) => $product->where('name', 'ILIKE', $like))
                    ->orWhereHasMorph('reference', [Enrollment::class], fn (Builder $enrollment) => $enrollment
                        ->whereHas('coursePackage', fn (Builder $package) => $package->where('name', 'ILIKE', $like))));

            // Only when the term has digits — "90" or "80,000" as shown in
            // the list; without this guard every term would match any amount.
            if ($number !== '' && $number !== '.') {
                $amountLike = '%'.$number.'%';
                $inner->orWhereRaw('total::text ILIKE ?', [$amountLike])
                    ->orWhereRaw('balance::text ILIKE ?', [$amountLike]);
            }
        });
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

    public function downloadPdf(Request $request, Invoice $invoice, InvoicePdfService $pdf): HttpResponse
    {
        $this->authorize('view', $invoice);

        $locale = InvoicePdfService::resolveRequestedLocale($request->query('locale'));

        return response($pdf->render($invoice, $locale), 200, [
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
