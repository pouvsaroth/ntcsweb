<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class RoleControllerTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_it_creates_a_custom_role_below_the_actors_own_level(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::ROLES_CREATE]);
        $adminRole = $admin->cachedRoles()->first();
        $adminRole->update(['level' => 80]);
        $admin->forgetPermissionCache();

        $response = $this->postJson('/api/v1/roles', [
            'name' => 'Accountant',
            'description' => 'Handles accounting and financial tasks',
            'level' => 40,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Accountant');
        $response->assertJsonPath('data.slug', 'accountant');
        $this->assertDatabaseHas('roles', ['slug' => 'accountant', 'tenant_id' => $this->tenant->id, 'is_system' => false]);
    }

    public function test_a_role_cannot_be_created_at_or_above_the_actors_own_level(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::ROLES_CREATE]);
        $adminRole = $admin->cachedRoles()->first();
        $adminRole->update(['level' => 80]);
        $admin->forgetPermissionCache();

        $response = $this->postJson('/api/v1/roles', ['name' => 'Super Staff', 'level' => 80]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('level');
        $this->assertDatabaseMissing('roles', ['name' => 'Super Staff']);
    }

    public function test_a_duplicate_role_name_within_the_tenant_is_rejected(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::ROLES_CREATE]);
        $admin->cachedRoles()->first()->update(['level' => 80]);
        $admin->forgetPermissionCache();
        Role::factory()->forTenant($this->tenant)->create(['name' => 'Accountant', 'slug' => 'accountant']);

        $response = $this->postJson('/api/v1/roles', ['name' => 'Accountant', 'level' => 30]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('name');
    }

    public function test_a_system_role_cannot_be_deleted(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::ROLES_DELETE]);
        $admin->cachedRoles()->first()->update(['level' => 90]);
        $admin->forgetPermissionCache();
        $systemRole = Role::factory()->forTenant($this->tenant)->system()->create(['level' => 40]);

        $this->deleteJson("/api/v1/roles/{$systemRole->id}")->assertForbidden();
    }

    /**
     * A system role's permissions/description ARE editable (this is the
     * whole point of the Roles screen for the four built-in roles), but its
     * name/slug/level are locked — those are referenced by slug across the
     * codebase (auto-provisioning, hierarchy levels) — so a name/level
     * submitted alongside the edit is silently dropped, not applied.
     */
    public function test_a_system_roles_permissions_and_description_can_be_edited_but_not_its_name_or_level(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::ROLES_UPDATE]);
        $admin->cachedRoles()->first()->update(['level' => 90]);
        $admin->forgetPermissionCache();
        $systemRole = Role::factory()->forTenant($this->tenant)->system()->create(['name' => 'Staff', 'slug' => 'staff', 'level' => 40]);

        $response = $this->putJson("/api/v1/roles/{$systemRole->id}", [
            'name' => 'Renamed',
            'level' => 5,
            'description' => 'Front-desk and registration duties.',
            'permissions' => [Permissions::STUDENTS_VIEW],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Staff');
        $response->assertJsonPath('data.description', 'Front-desk and registration duties.');
        $this->assertDatabaseHas('roles', ['id' => $systemRole->id, 'name' => 'Staff', 'slug' => 'staff', 'level' => 40]);
        $this->assertEqualsCanonicalizing(
            [Permissions::STUDENTS_VIEW],
            $systemRole->fresh()->permissions()->pluck('slug')->all(),
        );
    }

    /**
     * The scenario the "system roles skip the outranks check" carve-out in
     * RolePolicy::update() exists for: nobody outranks their own
     * highest-held role, so without it a School Admin could never adjust
     * the School Admin role's own permissions.
     */
    public function test_an_admin_can_edit_their_own_system_roles_permissions(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::ROLES_UPDATE]);
        $ownRole = $admin->cachedRoles()->first();
        $ownRole->forceFill(['is_system' => true, 'slug' => Role::SCHOOL_ADMIN, 'level' => Role::LEVELS[Role::SCHOOL_ADMIN]])->save();
        $admin->forgetPermissionCache();

        $response = $this->putJson("/api/v1/roles/{$ownRole->id}", [
            'permissions' => [Permissions::ROLES_UPDATE, Permissions::USERS_VIEW],
        ]);

        $response->assertOk();
        $this->assertEqualsCanonicalizing(
            [Permissions::ROLES_UPDATE, Permissions::USERS_VIEW],
            $ownRole->fresh()->permissions()->pluck('slug')->all(),
        );
    }

    public function test_it_updates_a_custom_roles_name_and_permissions(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::ROLES_CREATE, Permissions::ROLES_UPDATE]);
        $admin->cachedRoles()->first()->update(['level' => 80]);
        $admin->forgetPermissionCache();
        $role = Role::factory()->forTenant($this->tenant)->create(['name' => 'Accountant', 'slug' => 'accountant', 'level' => 30]);

        $response = $this->putJson("/api/v1/roles/{$role->id}", [
            'name' => 'Senior Accountant',
            'level' => 35,
            'permissions' => [Permissions::STAFF_VIEW, Permissions::STUDENTS_VIEW],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Senior Accountant');
        $response->assertJsonPath('data.slug', 'senior-accountant');
        $this->assertDatabaseHas('roles', ['id' => $role->id, 'name' => 'Senior Accountant', 'level' => 35]);
        $this->assertEqualsCanonicalizing(
            [Permissions::STAFF_VIEW, Permissions::STUDENTS_VIEW],
            $role->fresh()->permissions()->pluck('slug')->all(),
        );
    }

    /** The list endpoint feeds the edit modal's pre-checked permission matrix — no per-row request. */
    public function test_the_role_list_eager_loads_permissions(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::ROLES_VIEW]);
        $role = Role::factory()->forTenant($this->tenant)->create();
        $role->permissions()->attach(Permission::query()->where('slug', Permissions::STAFF_VIEW)->value('id'));

        $response = $this->getJson('/api/v1/roles');

        $response->assertOk();
        $roleData = collect($response->json('data'))->firstWhere('id', $role->id);
        $this->assertSame([Permissions::STAFF_VIEW], $roleData['permissions']);
    }
}
