<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Authorization\PermissionRegistry;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_users_permissions_are_the_union_of_their_roles(): void
    {
        $tenant = Tenant::factory()->create();
        app(PermissionRegistry::class)->sync();

        $viewUsers = Permission::query()->where('slug', Permissions::USERS_VIEW)->firstOrFail();
        $createUsers = Permission::query()->where('slug', Permissions::USERS_CREATE)->firstOrFail();

        $roleA = Role::factory()->forTenant($tenant)->create();
        $roleA->permissions()->attach($viewUsers);

        $roleB = Role::factory()->forTenant($tenant)->create();
        $roleB->permissions()->attach($createUsers);

        $user = User::factory()->forTenant($tenant)->create();
        $user->attachRoles($roleA, $roleB);

        $this->assertTrue($user->hasPermission(Permissions::USERS_VIEW));
        $this->assertTrue($user->hasPermission(Permissions::USERS_CREATE));
        $this->assertFalse($user->hasPermission(Permissions::USERS_DELETE));
    }

    public function test_revoking_a_role_immediately_invalidates_the_cached_permission_set(): void
    {
        $tenant = Tenant::factory()->create();
        app(PermissionRegistry::class)->sync();

        $permission = Permission::query()->where('slug', Permissions::USERS_DELETE)->firstOrFail();
        $role = Role::factory()->forTenant($tenant)->create();
        $role->permissions()->attach($permission);

        $user = User::factory()->forTenant($tenant)->create();
        $user->attachRoles($role);

        $this->assertTrue($user->hasPermission(Permissions::USERS_DELETE));

        $user->detachRoles($role);

        $this->assertFalse($user->hasPermission(Permissions::USERS_DELETE));
    }

    public function test_permission_cache_does_not_leak_across_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        app(PermissionRegistry::class)->sync();

        $permission = Permission::query()->where('slug', Permissions::USERS_VIEW)->firstOrFail();

        $roleA = Role::factory()->forTenant($tenantA)->create();
        $roleA->permissions()->attach($permission);

        $userA = User::factory()->forTenant($tenantA)->create();
        $userA->attachRoles($roleA);

        $userB = User::factory()->forTenant($tenantB)->create();
        $roleB = Role::factory()->forTenant($tenantB)->create(); // no permissions
        $userB->attachRoles($roleB);

        $this->assertTrue($userA->hasPermission(Permissions::USERS_VIEW));
        $this->assertFalse($userB->hasPermission(Permissions::USERS_VIEW));
    }

    public function test_super_admin_holds_every_permission_without_explicit_grants(): void
    {
        app(PermissionRegistry::class)->sync();

        $role = Role::factory()->platform()->system()->create([
            'slug' => Role::SUPER_ADMIN,
            'name' => 'Super Admin',
            'level' => Role::LEVELS[Role::SUPER_ADMIN],
        ]);

        $admin = User::factory()->forTenant(null)->create();
        $admin->attachRoles($role);

        $this->assertTrue($admin->isSuperAdmin());
        $this->assertTrue($admin->can(Permissions::TENANTS_DELETE));
    }
}
