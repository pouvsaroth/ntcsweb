<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\MonthlyInvoiceResource;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Billing\MonthlyBillingScheduleService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * The dashboard's "students due for monthly payment" widget — every active,
 * monthly-billed enrollment whose next payment is already due or falls
 * within the school's own School Settings → "monthly payment alert" window
 * (Tenant::monthlyPaymentAlertDays()). Same underlying data as the admin
 * Monthly Invoice tab (MonthlyInvoiceController), just pre-filtered to what
 * actually needs attention right now instead of the whole schedule.
 */
final class MonthlyPaymentAlertController extends Controller
{
    public function __construct(
        private readonly MonthlyBillingScheduleService $schedule,
        private readonly TenantContext $context,
    ) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        /** @var Tenant $tenant */
        $tenant = $this->context->getOrFail();

        return ApiResponse::success(
            MonthlyInvoiceResource::collection($this->schedule->due($tenant->monthlyPaymentAlertDays()))
        );
    }
}
