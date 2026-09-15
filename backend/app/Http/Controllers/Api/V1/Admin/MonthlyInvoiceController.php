<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\MonthlyInvoiceResource;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use App\Services\Billing\MonthlyBillingScheduleService;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The "Monthly Invoice" tab on the Invoices page: one row per enrollment
 * being billed monthly (payment_type = 'monthly' — see
 * EnrollmentService::enrollInPackage()), not one row per invoice. Read-only —
 * every actual invoice/payment action still happens through InvoiceController.
 *
 * See MonthlyBillingScheduleService for how `next_payment_date` is derived.
 */
final class MonthlyInvoiceController extends Controller
{
    public function __construct(private readonly MonthlyBillingScheduleService $schedule) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        $enrollments = ApiQuery::for($this->schedule->query(), $request)
            ->filterable(['status', 'student_id', 'class_id'])
            ->sortable(['enrolled_at', 'created_at'], default: '-enrolled_at')
            ->paginate();

        $this->schedule->annotate($enrollments);

        return ApiResponse::success(MonthlyInvoiceResource::collection($enrollments));
    }
}
