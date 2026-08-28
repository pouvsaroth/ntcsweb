<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Exceptions\Tenancy\TenantNotResolvedException;
use App\Models\Tenant;
use Closure;

/**
 * The single source of truth for "which school is this request about?".
 *
 * Registered as a singleton, so everything downstream — global query scopes,
 * cache keys, file paths, policies — reads the tenant from here rather than
 * from the request. Nothing in the application should ever take a tenant id
 * from user input.
 *
 * Three states:
 *
 *   UNRESOLVED  no tenant, no bypass. Any tenant-scoped query throws. This is
 *               the default, and it is what makes the system fail closed.
 *   TENANT      scoped to one school.
 *   PLATFORM    explicit bypass, granted only to platform super admins and to
 *               trusted internal code (seeders, migrations, cross-tenant jobs).
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    private bool $platform = false;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->platform = false;
    }

    public function forget(): void
    {
        $this->tenant = null;
        $this->platform = false;
    }

    /**
     * Enter platform mode: tenant scoping is lifted entirely.
     *
     * Only ever called from the tenancy middleware for an authenticated super
     * admin, or from trusted internal code via {@see withoutTenancy()}.
     */
    public function usePlatform(): void
    {
        $this->tenant = null;
        $this->platform = true;
    }

    public function isPlatform(): bool
    {
        return $this->platform;
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->getKey();
    }

    /**
     * @throws TenantNotResolvedException when no school is in context
     */
    public function getOrFail(): Tenant
    {
        return $this->tenant ?? throw TenantNotResolvedException::make();
    }

    public function idOrFail(): int
    {
        return $this->getOrFail()->getKey();
    }

    public function is(Tenant|int|null $tenant): bool
    {
        if ($tenant === null) {
            return false;
        }

        $id = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;

        return $this->id() === $id;
    }

    /**
     * Run a callback scoped to a specific school, then restore the previous
     * state. This is how queued jobs, console commands and cross-tenant reports
     * enter a tenant safely.
     *
     * @template TReturn
     *
     * @param  Closure(Tenant): TReturn  $callback
     * @return TReturn
     */
    public function runFor(Tenant $tenant, Closure $callback): mixed
    {
        return $this->restoring(function () use ($tenant, $callback) {
            $this->set($tenant);

            return $callback($tenant);
        });
    }

    /**
     * Run a callback with tenant scoping lifted.
     *
     * Every call site is a deliberate cross-tenant operation and should be
     * treated as security-sensitive during review. Never reachable from a
     * request unless the actor is a platform super admin.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withoutTenancy(Closure $callback): mixed
    {
        return $this->restoring(function () use ($callback) {
            $this->usePlatform();

            return $callback();
        });
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function restoring(Closure $callback): mixed
    {
        $previousTenant = $this->tenant;
        $previousPlatform = $this->platform;

        try {
            return $callback();
        } finally {
            $this->tenant = $previousTenant;
            $this->platform = $previousPlatform;
        }
    }
}
