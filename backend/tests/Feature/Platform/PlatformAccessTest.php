<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformStaff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

/**
 * The guard, permissions, audit and the staff/role/audit screens' routes.
 */
class PlatformAccessTest extends TestCase
{
    use HasPlatformStaff, RefreshDatabase;

    /** Routes a signed-out caller may reach: the sign-in steps. */
    private const PUBLIC_ROUTES = [
        'platform.auth.login',
        'platform.auth.two-factor',
        'platform.auth.two-factor.setup',
        'platform.auth.two-factor.confirm',
    ];

    /** Signed in, no permission: your own account. */
    private const SELF_ROUTES = [
        'platform.auth.session',
        'platform.auth.logout',
        'platform.auth.password',
        'platform.auth.recovery-codes',
    ];

    /** @return list<Route> */
    private function platformRoutes(): array
    {
        return array_values(array_filter(
            RouteFacade::getRoutes()->getRoutes(),
            fn (Route $route) => str_starts_with($route->uri(), 'api/v1/platform'),
        ));
    }

    public function test_every_platform_route_past_sign_in_names_a_platform_permission(): void
    {
        $routes = $this->platformRoutes();
        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $name = $route->getName();
            $middleware = $route->gatherMiddleware();

            $this->assertNotContains('ResolveTenant', array_map('class_basename', $middleware), "{$name} runs tenant middleware");

            if (in_array($name, self::PUBLIC_ROUTES, true)) {
                continue;
            }

            $this->assertContains('platform.staff', $middleware, "{$name} has no staff guard");

            if (in_array($name, self::SELF_ROUTES, true)) {
                continue;
            }

            $can = array_values(array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'platform.can:platform.')));
            $this->assertCount(1, $can, "{$name} names no platform.* permission");
        }
    }

    public function test_a_school_session_or_token_gets_401_on_every_platform_route(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($tenant)->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->actingAs($user, 'web');

        foreach ($this->platformRoutes() as $route) {
            if (in_array($route->getName(), self::PUBLIC_ROUTES, true)) {
                continue;
            }

            $uri = '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());
            $method = $route->methods()[0];

            $this->json($method, $uri)->assertUnauthorized();
            $this->withToken($token)->json($method, $uri)->assertUnauthorized();
        }
    }

    public function test_a_missing_permission_is_404_and_audited(): void
    {
        $staff = $this->platformStaff(['platform.audit.read']);

        $this->actingAsStaff($staff)->getJson('/api/v1/platform/staff')->assertNotFound();
        $this->getJson('/api/v1/platform/audit-logs')->assertOk();

        $this->assertDatabaseHas('bo_audit_logs', ['staff_id' => $staff->id, 'action' => 'permission.denied']);
    }

    public function test_the_owner_holds_every_permission(): void
    {
        $owner = $this->platformStaff();

        $this->actingAsStaff($owner);
        $this->getJson('/api/v1/platform/staff')->assertOk();
        $this->getJson('/api/v1/platform/roles')->assertOk();
        $this->getJson('/api/v1/platform/audit-logs')->assertOk();
    }

    public function test_creating_staff_returns_a_temporary_password_once_and_audits(): void
    {
        $owner = $this->platformStaff();
        $role = PlatformRole::query()->create(['name' => 'Support', 'permissions' => ['platform.audit.read']]);

        $response = $this->actingAsStaff($owner)->postJson('/api/v1/platform/staff', [
            'name' => 'New Person',
            'email' => 'New@Example.com',
            'roles' => [$role->id],
        ])->assertCreated();

        $this->assertNotEmpty($response->json('data.temporary_password'));
        $this->assertSame('new@example.com', $response->json('data.staff.email'));
        $this->assertTrue($response->json('data.staff.must_change_password'));

        $id = $response->json('data.staff.id');
        $this->getJson("/api/v1/platform/staff/{$id}")->assertOk()->assertJsonMissingPath('data.temporary_password');
        $this->assertDatabaseHas('bo_audit_logs', ['staff_id' => $owner->id, 'action' => 'staff.created', 'target_id' => $id]);
    }

    public function test_the_last_active_owner_cannot_be_disabled_or_demoted(): void
    {
        $owner = $this->platformStaff();
        $other = $this->platformStaff();
        $this->actingAsStaff($owner);

        // Not yourself.
        $this->patchJson("/api/v1/platform/staff/{$owner->id}", ['status' => 'disabled'])->assertUnprocessable();

        // Another Owner may go while one remains...
        $this->patchJson("/api/v1/platform/staff/{$other->id}", ['status' => 'disabled'])->assertOk();

        // ...but not the last one.
        $this->patchJson("/api/v1/platform/staff/{$owner->id}", ['roles' => []])->assertUnprocessable();
        $this->assertTrue($owner->fresh()->isOwner());
    }

    public function test_resetting_two_factor_and_password(): void
    {
        $owner = $this->platformStaff();
        $staff = $this->platformStaff(['platform.audit.read']);
        $this->actingAsStaff($owner);

        $this->postJson("/api/v1/platform/staff/{$staff->id}/reset-two-factor")->assertOk()
            ->assertJsonPath('data.two_factor_enabled', false);

        $password = $this->postJson("/api/v1/platform/staff/{$staff->id}/reset-password")->assertOk()
            ->json('data.temporary_password');

        $fresh = $staff->fresh();
        $this->assertFalse($fresh->hasTwoFactor());
        $this->assertTrue($fresh->must_change_password);
        $this->assertTrue(\Hash::check($password, $fresh->password));
        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'staff.two_factor_reset', 'target_id' => $staff->id]);
        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'staff.password_reset', 'target_id' => $staff->id]);
    }

    public function test_roles_create_update_copy_delete_and_the_owner_role_is_fixed(): void
    {
        $owner = $this->platformStaff();
        $ownerRole = PlatformRole::query()->where('is_owner', true)->firstOrFail();
        $this->actingAsStaff($owner);

        $this->getJson('/api/v1/platform/permissions')->assertOk()
            ->assertJsonPath('data.0.children.0.key', 'platform.staff.read');

        $id = $this->postJson('/api/v1/platform/roles', [
            'name' => 'Support',
            'permissions' => ['platform.audit.read'],
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/platform/roles', ['name' => 'Bad', 'permissions' => ['students.read']])->assertUnprocessable();

        $this->putJson("/api/v1/platform/roles/{$id}", [
            'name' => 'Support',
            'permissions' => ['platform.audit.read', 'platform.staff.read'],
        ])->assertOk()->assertJsonPath('data.permissions', ['platform.audit.read', 'platform.staff.read']);

        $copy = $this->postJson("/api/v1/platform/roles/{$id}/copy", ['name' => 'Support 2'])->assertCreated();
        $this->assertSame(['platform.audit.read', 'platform.staff.read'], $copy->json('data.permissions'));

        $this->putJson("/api/v1/platform/roles/{$ownerRole->id}", ['name' => 'X', 'permissions' => []])->assertStatus(409);
        $this->deleteJson("/api/v1/platform/roles/{$ownerRole->id}")->assertStatus(409);
        $this->deleteJson("/api/v1/platform/roles/{$id}")->assertNoContent();

        foreach (['role.created', 'role.updated', 'role.copied', 'role.deleted'] as $action) {
            $this->assertDatabaseHas('bo_audit_logs', ['staff_id' => $owner->id, 'action' => $action]);
        }
    }

    public function test_the_audit_log_filters(): void
    {
        $owner = $this->platformStaff();
        $this->actingAsStaff($owner);
        $this->postJson('/api/v1/platform/roles', ['name' => 'A', 'permissions' => []]);

        $this->getJson('/api/v1/platform/audit-logs?filter[action]=role.created')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.staff.id', $owner->id);

        $this->getJson('/api/v1/platform/audit-logs?from=2000-01-01&to=2000-01-02')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_staff_never_satisfy_the_school_api(): void
    {
        $owner = $this->platformStaff();

        $this->actingAsStaff($owner)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertInstanceOf(PlatformStaff::class, $owner);
    }
}
