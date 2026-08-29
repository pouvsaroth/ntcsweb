<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Student;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Authorization\PermissionRegistry;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for a real ordering bug: Laravel's default
 * middlewarePriority list runs SubstituteBindings (which performs the
 * tenant-scoped query for implicit route-model binding) before
 * ResolveTenant, unless bootstrap/app.php explicitly reorders them — see the
 * appendToPriorityList() call there.
 *
 * Every other test in this suite authenticates via actingAsTenantUser(),
 * which sets TenantContext directly before the request even starts — that
 * masks this bug completely, since it never depends on ResolveTenant's
 * timing. These tests deliberately avoid that helper and authenticate the
 * way a real client does: a bearer token resolved lazily, mid-request, by
 * the Sanctum guard — the only setup that actually exercises the ordering.
 */
class MiddlewareOrderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_bearer_token_request_can_fetch_a_single_resource_via_route_model_binding(): void
    {
        $tenant = Tenant::factory()->create();

        app(PermissionRegistry::class)->sync();

        $role = Role::factory()->forTenant($tenant)->create();
        $role->permissions()->attach(Permission::query()->where('slug', Permissions::STUDENTS_VIEW)->pluck('id'));

        $user = User::factory()->forTenant($tenant)->create();
        $user->attachRoles($role);

        $student = $this->withoutTenancy(fn () => Student::factory()->forTenant($tenant)->create());

        $token = $user->createToken('regression-test')->plainTextToken;

        // No actingAsTenantUser()/actingInTenant() here — TenantContext must
        // come entirely from ResolveTenant resolving this bearer token via
        // AuthenticatedUserTenantResolver, at the correct point in the
        // pipeline. Before the fix, this 500'd with "no tenant in context"
        // because SubstituteBindings queried Student::findOrFail() first.
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/students/{$student->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $student->id);
    }

    private function withoutTenancy(\Closure $callback): mixed
    {
        return app(\App\Support\Tenancy\TenantContext::class)->withoutTenancy($callback);
    }
}
