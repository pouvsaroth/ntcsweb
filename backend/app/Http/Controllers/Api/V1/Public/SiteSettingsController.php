<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * Public branding for the school website — name, logo, contact details. No
 * auth required; the `tenant.required` middleware on this route group is
 * what actually gates it, since without a resolved tenant there is no school
 * to describe.
 *
 * Doubles as the frontend's signal for "was a tenant already implied by this
 * hostname?" — a 404 here (tenant.required aborting) is exactly the case
 * where the SPA still needs to ask the visitor which school they mean (see
 * the login form's "School" field, shown only on a central domain).
 */
final class SiteSettingsController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(): JsonResponse
    {
        $tenant = $this->context->getOrFail();

        return ApiResponse::success([
            'name' => $tenant->name,
            'logo' => $tenant->logo,
            'email' => $tenant->email,
            'phone' => $tenant->phone,
            'address' => $tenant->address,
        ]);
    }
}
