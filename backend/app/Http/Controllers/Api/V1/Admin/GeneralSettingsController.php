<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateGeneralSettingsRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Services\Academic\StudentIdGenerator;
use App\Services\Billing\BillingNumberGenerator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * A singleton, not a REST resource — same shape as AboutPageController, for
 * the same reason: there is exactly one settings blob per school, stored in
 * `tenants.settings` (see Tenant::setting()). Owns `student_id_prefix`,
 * `invoice_prefix`, and `receipt_prefix` — every scalar a school can tune
 * lands here rather than each growing its own endpoint.
 */
final class GeneralSettingsController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly StudentIdGenerator $studentIdGenerator,
        private readonly BillingNumberGenerator $billingNumbers,
    ) {}

    public function show(): JsonResponse
    {
        $tenant = $this->context->getOrFail();
        $this->authorize('view', $tenant);

        return ApiResponse::success($this->payload($tenant));
    }

    public function update(UpdateGeneralSettingsRequest $request): JsonResponse
    {
        $tenant = $this->context->getOrFail();
        $this->authorize('update', $tenant);

        // Read-modify-write on the same jsonb column every other setting
        // lives in — refresh right before reading it so a `$tenant` instance
        // resolved earlier in the request can never clobber a setting saved
        // elsewhere moments ago. See AboutPageController::update() for the
        // identical concern.
        $tenant->refresh();

        $tenant->update([
            'settings' => array_filter([
                ...($tenant->settings ?? []),
                'student_id_prefix' => $request->validated('student_id_prefix') ?? $tenant->setting('student_id_prefix'),
                'invoice_prefix' => $request->validated('invoice_prefix') ?? $tenant->setting('invoice_prefix'),
                'receipt_prefix' => $request->validated('receipt_prefix') ?? $tenant->setting('receipt_prefix'),
            ], static fn ($value) => $value !== null),
        ]);

        return ApiResponse::success($this->payload($tenant->fresh()));
    }

    private function payload(Tenant $tenant): array
    {
        return [
            'student_id_prefix' => $this->studentIdGenerator->prefixFor($tenant),
            'invoice_prefix' => $this->billingNumbers->invoicePrefixFor($tenant),
            'receipt_prefix' => $this->billingNumbers->receiptPrefixFor($tenant),
        ];
    }
}
