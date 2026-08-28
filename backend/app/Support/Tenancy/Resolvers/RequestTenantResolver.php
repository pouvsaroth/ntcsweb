<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Resolvers;

use App\Models\Tenant;
use App\Support\Tenancy\Contracts\TenantResolver;
use App\Support\Tenancy\TenantHost;
use Illuminate\Http\Request;

/**
 * Resolves a school from an explicit `X-Tenant: <slug>` header or `?tenant=`
 * parameter — but only on a central domain, and only when enabled in config.
 *
 * This exists so the whole platform is usable on http://localhost:8080 before
 * any DNS exists, and so a super admin can act inside one school from the
 * platform console.
 *
 * It does not weaken isolation. Naming a tenant here only sets the *requested*
 * tenant; EnsureTenantMatchesUser then rejects any authenticated user whose own
 * tenant_id differs, so a School A user asking for School B gets 403, not data.
 */
final readonly class RequestTenantResolver implements TenantResolver
{
    public function __construct(private TenantHost $host) {}

    public function resolve(Request $request): ?Tenant
    {
        if (! config('tenancy.allow_request_resolution')) {
            return null;
        }

        $hostname = $this->host->normalise($request->getHost());

        // On a school's own domain the hostname is authoritative; letting a
        // header override it would be a way to shop for tenants.
        if ($hostname === null || ! $this->host->isCentral($hostname)) {
            return null;
        }

        $identifier = $request->header(config('tenancy.request_header'))
            ?? $request->input(config('tenancy.request_parameter'));

        if (! is_string($identifier) || trim($identifier) === '') {
            return null;
        }

        $identifier = trim($identifier);

        return Tenant::query()
            ->active()
            ->where(function ($query) use ($identifier) {
                $query->where('slug', mb_strtolower($identifier));

                if (ctype_digit($identifier)) {
                    $query->orWhere('id', (int) $identifier);
                }
            })
            ->first();
    }
}
