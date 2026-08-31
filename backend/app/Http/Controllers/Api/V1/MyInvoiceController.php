<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use App\Services\Billing\InvoicePdfService;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;

/**
 * Student self-service: "my invoices," not "all invoices." No
 * `invoices.view` permission is required here — a student sees their own
 * billing purely by identity (User::student()), the same rule InvoicePolicy
 * already enforces for a direct `GET /invoices/{id}` hit. This controller
 * only ever queries through `$user->student`, so there is no path to another
 * student's records regardless of what id is requested.
 */
final class MyInvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $student = $this->studentOrFail($request);

        $query = Invoice::query()->where('student_id', $student->id);

        $invoices = ApiQuery::for($query, $request)
            ->filterable(['status'])
            ->sortable(['invoice_date', 'due_date', 'total', 'balance', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(InvoiceResource::collection($invoices));
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        $student = $this->studentOrFail($request);
        abort_unless($invoice->student_id === $student->id, 404);

        return ApiResponse::success(new InvoiceResource(
            $invoice->load(['items.product', 'items.variant', 'payments.receivedBy'])
        ));
    }

    public function downloadPdf(Request $request, Invoice $invoice, InvoicePdfService $pdf): HttpResponse
    {
        $student = $this->studentOrFail($request);
        abort_unless($invoice->student_id === $student->id, 404);

        return response($pdf->render($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->filename($invoice).'"',
        ]);
    }

    private function studentOrFail(Request $request)
    {
        $student = $request->user()?->student;

        if ($student === null) {
            throw ValidationException::withMessages([
                'student' => 'This account is not linked to a student record.',
            ]);
        }

        return $student;
    }
}
