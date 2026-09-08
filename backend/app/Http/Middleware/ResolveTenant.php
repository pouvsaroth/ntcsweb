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
use Stancl\Tenancy\Database\DatabaseManager;
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
        private DatabaseManager $tenantDatabases,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolvers->resolve($request);

        if ($tenant !== null) {
            $this->context->set($tenant);

            // Registers a `tenant` connection pointing at this school's own
            // database, if it has one — cheap (just config, no query), and
            // purely additive: nothing reads from it until a model actually
            // opts in via `protected $connection = 'tenant'`, so resolving a
            // school with no dedicated database yet is still perfectly fine
            // for everything else. See BelongsToTenant's docblock for why
            // most tables don't opt in yet.
            //
            // Skipped under the test runner: feature tests exercise a
            // converted module against the ordinary shared test database
            // (see config/database.php's `tenant` connection and
            // Tests\TestCase::$connectionsToTransact) rather than
            // provisioning a real, physical per-tenant database for every
            // one of hundreds of fake tenants a test suite creates.
            if (! app()->environment('testing')) {
                $this->tenantDatabases->createTenantConnection($tenant);
            }

            // Each school runs on its own clock and language.
            config(['app.timezone' => $tenant->timezone]);
            date_default_timezone_set($tenant->timezone);
            app()->setLocale($tenant->locale);
        } else {
            // A stale `tenant` connection from a previous request has no
            // business surviving into this one — harmless for typical
            // one-request-per-process PHP, but queue workers and any other
            // long-lived process must not let School A's connection leak
            // into a request that resolved no tenant at all. Skipped under
            // the test runner for the same reason the registration above
            // is: it would delete config/database.php's static `tenant`
            // fallback outright (purgeTenantConnection() unsets the config
            // key, not just the open connection), and every test's
            // teardown expects that connection to still exist.
            if (! app()->environment('testing')) {
                $this->tenantDatabases->purgeTenantConnection();
            }

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
