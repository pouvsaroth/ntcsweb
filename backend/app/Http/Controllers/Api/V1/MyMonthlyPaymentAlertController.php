<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Enrollment;
use App\Models\Tenant;
use App\Services\Billing\MonthlyBillingScheduleService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Student self-service: does *this* student have a monthly payment coming
 * due soon? Backs the payment-due popup shown on the public site once a
 * student is logged in. Identity-gated (User::student()), same pattern as
 * MyInvoiceController — no permission required, and there is no path here to
 * another student's schedule regardless of input, since everything is
 * filtered by `$user->student` before anything else runs.
 */
final class MyMonthlyPaymentAlertController extends Controller
{
    public function __construct(
        private readonly MonthlyBillingScheduleService $schedule,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $student = $request->user()?->student;

        if ($student === null) {
            return ApiResponse::success([]);
        }

        /** @var Tenant $tenant */
        $tenant = $this->context->getOrFail();

        $due = $this->schedule->due($tenant->monthlyPaymentAlertDays())
            ->filter(fn (Enrollment $enrollment) => $enrollment->student_id === $student->id)
            ->values();

        return ApiResponse::success($due->map(fn (Enrollment $enrollment) => [
            'course' => $enrollment->coursePackage?->name,
            'next_payment_date' => $enrollment->next_payment_date,
        ]));
    }
}
