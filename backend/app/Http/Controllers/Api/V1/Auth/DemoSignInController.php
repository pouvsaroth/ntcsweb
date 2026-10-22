<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Platform\Demo\Demos;
use App\Support\Tenancy\Resolvers\DomainTenantResolver;
use App\Support\Tenancy\Resolvers\RequestTenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The console end of "Try the demo" (docs/backoffice/stage-12-demo.md): the
 * website's one-time ticket becomes a signed-in session as the visitor's
 * own demo account.
 */
final class DemoSignInController extends Controller
{
    public function __construct(private readonly Demos $demos) {}

    public function enter(Request $request, DomainTenantResolver $domain, RequestTenantResolver $named): JsonResponse
    {
        $data = $request->validate(['ticket' => ['required', 'string', 'max:100']]);

        // Only the school the ticket is for (its domain, or the tenant named
        // on the shared domain) — never whoever was signed in before.
        $explicit = $domain->resolve($request) ?? $named->resolve($request);

        $this->demos->enter($request, $data['ticket'], $explicit?->getKey());

        // No user payload: this request's tenant context may still be the
        // previous user's. The console reloads and asks /auth/me.
        return ApiResponse::success(message: __('Signed in.'));
    }
}
