<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Platform\Support\SupportViewSession;
use App\Support\Tenancy\Resolvers\DomainTenantResolver;
use App\Support\Tenancy\Resolvers\RequestTenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The console end of "Open support view" (docs/backoffice/stage-6-support.md, 6b-2):
 * the one-time ticket from the back office becomes a read-only browser
 * session as the school user. GuardSupportView takes it from there.
 */
final class SupportViewController extends Controller
{
    public function __construct(private readonly SupportViewSession $views) {}

    public function enter(Request $request, DomainTenantResolver $domain, RequestTenantResolver $named): JsonResponse
    {
        $data = $request->validate(['ticket' => ['required', 'string', 'max:100']]);

        // Only the school the ticket is for: a school's own domain, or a
        // tenant named on the shared domain, must be that school. The school
        // of whoever was signed in before does not count — they are signed out.
        $explicit = $domain->resolve($request) ?? $named->resolve($request);

        $session = $this->views->enter($request, $data['ticket'], $explicit?->getKey());

        // No user payload here: this request's tenant context may still be the
        // previous user's. The console reloads and asks /auth/me.
        return ApiResponse::success(['support_view' => SupportViewSession::present($session->load('staff:id,name'))]);
    }
}
