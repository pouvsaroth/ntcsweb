<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Support\Str;

/**
 * Hostname arithmetic for tenancy: normalising, classifying and cache-keying
 * hostnames. Kept out of the resolver so it can be unit-tested on its own and
 * reused by tenant-domain management.
 */
final class TenantHost
{
    /**
     * Lower-case, strip any port and trailing dot, reject anything that is not
     * a plausible hostname.
     */
    public function normalise(?string $host): ?string
    {
        if ($host === null) {
            return null;
        }

        $host = mb_strtolower(trim($host));
        $host = Str::before($host, ':');
        $host = rtrim($host, '.');

        if ($host === '' || mb_strlen($host) > 253) {
            return null;
        }

        return $host;
    }

    /**
     * A platform address rather than a school's.
     */
    public function isCentral(string $hostname): bool
    {
        return in_array($hostname, $this->centralDomains(), true);
    }

    /**
     * "newtech.ntcsweb.com" -> "newtech" when the root domain is ntcsweb.com.
     *
     * Returns null for the bare root domain, for central domains and for
     * anything outside the root domain (those are custom domains and must have
     * an explicit tenant_domains row). Multi-level labels such as
     * "a.b.ntcsweb.com" are rejected: a slug is always a single label.
     */
    public function subdomainOfRoot(string $hostname): ?string
    {
        $root = $this->normalise(config('tenancy.root_domain'));

        if ($root === null || $hostname === $root) {
            return null;
        }

        if (! str_ends_with($hostname, '.'.$root)) {
            return null;
        }

        $label = Str::beforeLast($hostname, '.'.$root);

        if ($label === '' || str_contains($label, '.')) {
            return null;
        }

        return $label;
    }

    public function cacheKey(string $hostname): string
    {
        return sprintf('%s:host:%s', config('tenancy.cache.prefix'), $hostname);
    }

    /**
     * @return list<string>
     */
    public function centralDomains(): array
    {
        return array_values(array_filter(array_map(
            fn (string $domain): ?string => $this->normalise($domain),
            (array) config('tenancy.central_domains', []),
        )));
    }
}
