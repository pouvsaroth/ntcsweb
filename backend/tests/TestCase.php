<?php

namespace Tests;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Enter a school's context directly, bypassing hostname/header resolution.
     * Most unit and policy tests care about tenant scoping, not how the tenant
     * was resolved — that is covered separately by tenancy resolver tests.
     *
     * Only meaningful for calls made directly against models/services in the
     * current process. A real HTTP test call (getJson(), postJson(), ...) runs
     * the actual ResolveTenant middleware, which re-resolves the tenant from
     * the request from scratch and overwrites whatever this sets — simulate a
     * specific tenant for those via the request itself (a Host header, or the
     * X-Tenant header on a central domain), not this helper.
     */
    protected function actingInTenant(Tenant $tenant): static
    {
        app(TenantContext::class)->set($tenant);

        return $this;
    }

    protected function actingAsPlatform(): static
    {
        app(TenantContext::class)->usePlatform();

        return $this;
    }

    /**
     * Authenticate as a user AND resolve their tenant, the way a real request
     * would via AuthenticatedUserTenantResolver. Using actingAs() alone would
     * leave TenantContext empty and every tenant-scoped query would throw.
     */
    protected function actingAsTenantUser(User $user): static
    {
        if ($user->tenant_id !== null) {
            $this->actingInTenant($user->tenant);
        } else {
            $this->actingAsPlatform();
        }

        $this->actingAs($user);

        return $this;
    }
}
