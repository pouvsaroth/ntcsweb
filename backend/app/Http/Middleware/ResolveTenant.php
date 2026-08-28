<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantResolverChain;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the tenant for the request. Runs on every API request, including
 * unauthenticated ones — the public school website needs a tenant too.
 *
 * Never throws: a request that resolves no tenant simply proceeds with an empty
 * context, and the first tenant-scoped query is what fails. Routes that require
 * a school should say so with the `tenant.required` middleware.
 */
final readonly class ResolveTenant
{
    public function __construct(
        private TenantResolverChain $resolvers,
        private TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolvers->resolve($request);

        if ($tenant !== null) {
            $this->context->set($tenant);

            // Each school runs on its own clock and language.
            config(['app.timezone' => $tenant->timezone]);
            date_default_timezone_set($tenant->timezone);
            app()->setLocale($tenant->locale);
        } else {
            $user = Auth::guard(config('tenancy.auth_guard'))->user();

            // A super admin with no school in context operates platform-wide.
            if ($user instanceof User && $user->isSuperAdmin()) {
                $this->context->usePlatform();
            }
        }

        Log::withContext([
            'tenant_id' => $this->context->id(),
            'tenant_mode' => $this->context->isPlatform() ? 'platform' : ($this->context->has() ? 'tenant' : 'none'),
        ]);

        $response = $next($request);

        // Lets the SPA confirm which school it is talking to, and makes
        // cross-tenant bugs obvious in the browser network tab.
        if ($this->context->has()) {
            $response->headers->set('X-Tenant-Id', (string) $this->context->id());
        }

        return $response;
    }
}
