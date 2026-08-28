<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Resolvers;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\Contracts\TenantResolver;
use Illuminate\Http\Request;

/**
 * Last resort: take the tenant from the authenticated user's own tenant_id.
 *
 * This is the only resolver that is inherently trustworthy — the value comes
 * from the users table, not from the request — which is why nothing further
 * needs to validate it. It runs last simply because hostname resolution carries
 * more intent when it is available.
 *
 * Returns null for platform super admins (tenant_id IS NULL); they are placed
 * in platform mode by the middleware instead.
 */
final class AuthenticatedUserTenantResolver implements TenantResolver
{
    public function resolve(Request $request): ?Tenant
    {
        $user = $request->user();

        if (! $user instanceof User || $user->tenant_id === null) {
            return null;
        }

        // Loaded through the relation so it is cached on the user for the rest
        // of the request rather than queried again by every consumer.
        $tenant = $user->tenant;

        return $tenant instanceof Tenant && $tenant->isActive() ? $tenant : null;
    }
}
