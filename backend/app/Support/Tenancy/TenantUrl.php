<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Tenant;

/**
 * Builds links that point at the right school's front end.
 *
 * Password reset and email verification links are the one place the backend has
 * to know the SPA's address, and getting it wrong sends a school's users to
 * another school's site. Centralised here so there is a single rule:
 * a tenant's own hostname when it has one, the configured front end otherwise.
 */
final class TenantUrl
{
    public function __construct(private readonly TenantHost $host) {}

    public function frontend(?Tenant $tenant, string $path = '/', array $query = []): string
    {
        $base = rtrim($this->baseUrl($tenant), '/');
        $url = $base.'/'.ltrim($path, '/');

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    public function baseUrl(?Tenant $tenant): string
    {
        if ($tenant === null) {
            return (string) config('app.frontend_url', config('app.url'));
        }

        $hostname = $this->host->normalise($tenant->hostname());

        if ($hostname === null || $this->host->isCentral($hostname)) {
            // Local development: everything is served from one origin, so the
            // school is carried as a query parameter instead of a hostname.
            return (string) config('app.frontend_url', config('app.url'));
        }

        return sprintf('%s://%s', config('app.url_scheme', 'https'), $hostname);
    }
}
