<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Exceptions\Tenancy\TenantMismatchException;
use App\Exceptions\Tenancy\TenantNotResolvedException;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The single most important guarantee in this codebase:
 * a Tenant A user must never be able to read or write Tenant B data.
 *
 * Each test attacks the boundary from a different layer — the query scope,
 * the write guard, the HTTP middleware, and the raw model relation — because a
 * real regression is far more likely to slip past one layer than all four at
 * once.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::factory()->create(['name' => 'NewTech Computer School', 'slug' => 'newtech']);
        $this->tenantB = Tenant::factory()->create(['name' => 'ABC School', 'slug' => 'abcschool']);
    }

    public function test_query_scope_hides_other_tenants_records(): void
    {
        $this->actingInTenant($this->tenantA);
        AuditLog::query()->create(['event' => 'a.event']);

        $this->actingInTenant($this->tenantB);
        AuditLog::query()->create(['event' => 'b.event']);

        $this->actingInTenant($this->tenantA);

        $events = AuditLog::query()->pluck('event')->all();

        $this->assertSame(['a.event'], $events);
    }

    public function test_query_throws_when_no_tenant_is_in_context(): void
    {
        app(TenantContext::class)->forget();

        $this->expectException(TenantNotResolvedException::class);

        AuditLog::query()->get();
    }

    public function test_platform_mode_can_see_every_tenant(): void
    {
        $this->actingInTenant($this->tenantA);
        AuditLog::query()->create(['event' => 'a.event']);

        $this->actingInTenant($this->tenantB);
        AuditLog::query()->create(['event' => 'b.event']);

        $this->actingAsPlatform();

        $this->assertSame(2, AuditLog::query()->count());
    }

    public function test_creating_a_record_stamps_the_current_tenant_automatically(): void
    {
        $this->actingInTenant($this->tenantA);

        $log = AuditLog::query()->create(['event' => 'stamped']);

        $this->assertSame($this->tenantA->id, $log->tenant_id);
    }

    public function test_a_record_cannot_be_reassigned_to_another_tenant(): void
    {
        $this->actingInTenant($this->tenantA);
        $log = AuditLog::query()->create(['event' => 'a.event']);

        $this->expectException(TenantMismatchException::class);

        $log->tenant_id = $this->tenantB->id;
        $log->save();
    }

    public function test_middleware_rejects_a_tenant_user_authenticated_against_another_tenant(): void
    {
        $userA = User::factory()->forTenant($this->tenantA)->create();

        // A real HTTP request runs the actual ResolveTenant middleware, which
        // re-resolves the tenant from scratch on every request — a tenant set
        // directly on TenantContext beforehand would just be overwritten by
        // it. To simulate "Tenant A's user hitting Tenant B", the request
        // itself has to point at Tenant B, the same way a client on a central
        // domain does in local development: the X-Tenant header.
        $this->actingAs($userA);

        $response = $this->withHeader('X-Tenant', $this->tenantB->slug)->getJson('/api/v1/auth/me');

        $response->assertForbidden();
        $response->assertJsonPath('error.code', 'TENANT_MISMATCH');
    }

    public function test_a_user_authenticated_against_their_own_tenant_succeeds(): void
    {
        $userA = User::factory()->forTenant($this->tenantA)->create();

        $this->actingAsTenantUser($userA);

        $response = $this->getJson('/api/v1/auth/me');

        $response->assertOk();
        $response->assertJsonPath('data.id', $userA->id);
    }

    public function test_role_visible_to_scope_hides_other_tenants_roles(): void
    {
        $roleA = Role::factory()->forTenant($this->tenantA)->create(['slug' => 'librarian']);
        Role::factory()->forTenant($this->tenantB)->create(['slug' => 'librarian']);

        $userA = User::factory()->forTenant($this->tenantA)->create();

        $visible = Role::query()->visibleTo($userA)->pluck('id')->all();

        $this->assertSame([$roleA->id], $visible);
    }

    public function test_users_across_tenants_may_share_the_same_email(): void
    {
        User::factory()->forTenant($this->tenantA)->create(['email' => 'admin@school.test']);

        // Must not raise a unique constraint violation.
        $userB = User::factory()->forTenant($this->tenantB)->create(['email' => 'admin@school.test']);

        $this->assertSame($this->tenantB->id, $userB->tenant_id);
        $this->assertSame(2, User::query()->withoutGlobalScopes()->where('email', 'admin@school.test')->count());
    }
}
