<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Tenancy\TenantMismatchException;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The cross-tenant guard.
 *
 * Once a user is authenticated, their own tenant_id — a database fact, not
 * something the request can influence — must agree with whatever tenant the
 * resolvers settled on. A School A user hitting School B's domain, or sending
 * `X-Tenant: school-b`, is rejected here with 403 before any query runs.
 *
 * Platform super admins are exempt: acting inside an arbitrary school is their
 * job. Everything they do is still subject to policies and is audit-logged.
 *
 * This is defence in depth. TenantScope would already have constrained the
 * queries; this turns a silently-empty result into an explicit refusal.
 */
final readonly class EnsureTenantMatchesUser
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard(config('tenancy.auth_guard'))->user();

        if (! $user instanceof User || $user->isSuperAdmin()) {
            return $next($request);
        }

        $resolved = $this->context->id();

        // A tenant user whose school could not be resolved at all: their own
        // tenant is inactive or deleted, or the context was never established.
        if ($resolved === null) {
            throw TenantMismatchException::forUser((int) $user->tenant_id, null);
        }

        if ((int) $user->tenant_id !== $resolved) {
            throw TenantMismatchException::forUser((int) $user->tenant_id, $resolved);
        }

        return $next($request);
    }
}
