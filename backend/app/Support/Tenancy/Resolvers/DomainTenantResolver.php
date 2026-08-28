<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Resolvers;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Support\Tenancy\Contracts\TenantResolver;
use App\Support\Tenancy\TenantHost;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;

/**
 * Resolves a school from the request hostname.
 *
 * Two ways a hostname can match, in order:
 *
 *   1. An explicit row in tenant_domains  — covers custom domains such as
 *      school.example.edu.kh, and any subdomain that has been pinned.
 *   2. A subdomain of the configured root domain matched against tenants.slug —
 *      newtech.ntcsweb.com resolves the "newtech" slug with no extra row, so a
 *      school works the moment it is created.
 *
 * Central domains (localhost, admin.ntcsweb.com) never resolve here; they are
 * the platform's own addresses.
 *
 * Runs on every request, so the hostname -> tenant id lookup is cached. Only
 * the id is cached, and the model is then loaded normally, so a tenant's own
 * fields are never served stale from this layer.
 */
final readonly class DomainTenantResolver implements TenantResolver
{
    public function __construct(
        private CacheRepository $cache,
        private TenantHost $host,
    ) {}

    public function resolve(Request $request): ?Tenant
    {
        $hostname = $this->host->normalise($request->getHost());

        if ($hostname === null || $this->host->isCentral($hostname)) {
            return null;
        }

        $tenantId = $this->cache->remember(
            $this->host->cacheKey($hostname),
            config('tenancy.cache.ttl'),
            fn () => $this->lookup($hostname) ?? 0, // 0 is cached as "no such host"
        );

        if (! $tenantId) {
            return null;
        }

        return Tenant::query()->active()->find($tenantId);
    }

    private function lookup(string $hostname): ?int
    {
        $explicit = TenantDomain::query()
            ->where('hostname', $hostname)
            ->value('tenant_id');

        if ($explicit !== null) {
            return (int) $explicit;
        }

        $slug = $this->host->subdomainOfRoot($hostname);

        if ($slug === null) {
            return null;
        }

        $bySlug = Tenant::query()->where('slug', $slug)->value('id');

        return $bySlug === null ? null : (int) $bySlug;
    }
}
