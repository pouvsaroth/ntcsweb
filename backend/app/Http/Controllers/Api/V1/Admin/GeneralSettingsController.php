<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateGeneralSettingsRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Academic\StudentIdGenerator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * A singleton, not a REST resource — same shape as AboutPageController, for
 * the same reason: there is exactly one settings blob per school, stored in
 * `tenants.settings` (see Tenant::setting()). This controller only owns
 * `student_id_prefix` today; a later general setting would add its own key
 * here rather than a new endpoint.
 */
final class GeneralSettingsController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly StudentIdGenerator $studentIdGenerator,
    ) {}

    public function show(): JsonResponse
    {
        $tenant = $this->context->getOrFail();
        $this->authorize('view', $tenant);

        return ApiResponse::success([
            'student_id_prefix' => $this->studentIdGenerator->prefixFor($tenant),
        ]);
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
            'settings' => [
                ...($tenant->settings ?? []),
                'student_id_prefix' => $request->validated('student_id_prefix'),
            ],
        ]);

        return ApiResponse::success([
            'student_id_prefix' => $this->studentIdGenerator->prefixFor($tenant->fresh()),
        ]);
    }
}
