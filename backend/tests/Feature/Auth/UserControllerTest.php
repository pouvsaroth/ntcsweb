<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    /**
     * The manual counterpart to auto-provisioning at Student creation — how
     * a bulk-imported (never auto-provisioned) student gets portal access.
     */
    public function test_linking_an_unlinked_student_creates_a_user_with_the_student_role(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::USERS_CREATE]);
        $student = Student::factory()->create(['user_id' => null]);

        $response = $this->postJson('/api/v1/users', [
            'student_id' => $student->id,
            'name' => $student->fullName(),
            'phone' => '012345678',
        ]);

        $response->assertCreated();
        // Default password is the phone number itself — see
        // UserProvisioningService.
        $response->assertJsonPath('meta.temporary_password', '012345678');

        $student->refresh();
        $this->assertNotNull($student->user_id);
        $this->assertTrue($student->user->hasRole('student'));
    }

    public function test_an_already_linked_student_cannot_be_linked_again(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::USERS_CREATE]);
        $existingUser = User::factory()->forTenant($this->tenant)->create();
        $student = Student::factory()->create(['user_id' => $existingUser->id]);

        $response = $this->postJson('/api/v1/users', [
            'student_id' => $student->id,
            'name' => $student->fullName(),
            'phone' => '012345678',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('student_id');
    }

    /**
     * Item 8's core guarantee applied to the one place this feature lets an
     * admin pick a role directly: RolePolicy::assign's outranks() check.
     */
    public function test_a_standalone_account_cannot_be_granted_a_role_the_actor_does_not_outrank(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::USERS_CREATE, Permissions::ROLES_ASSIGN]);
        $admin->cachedRoles()->first()->update(['level' => 50]);
        $admin->forgetPermissionCache();
        $powerfulRole = Role::factory()->forTenant($this->tenant)->create(['level' => 80]);

        $response = $this->postJson('/api/v1/users', [
            'name' => 'Attacker', 'phone' => '011112222', 'role_id' => $powerfulRole->id,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('role_id');
        $this->assertDatabaseMissing('users', ['name' => 'Attacker']);
    }

    public function test_a_standalone_account_can_be_granted_a_role_the_actor_outranks(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::USERS_CREATE, Permissions::ROLES_ASSIGN]);
        $admin->cachedRoles()->first()->update(['level' => 80]);
        $admin->forgetPermissionCache();
        $juniorRole = Role::factory()->forTenant($this->tenant)->create(['level' => 40]);

        $response = $this->postJson('/api/v1/users', [
            'name' => 'New Admin', 'phone' => '011113333', 'role_id' => $juniorRole->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('users', ['name' => 'New Admin', 'tenant_id' => $this->tenant->id]);
    }

    public function test_an_admin_can_update_a_users_name_and_email(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::USERS_UPDATE]);
        $target = User::factory()->forTenant($this->tenant)->create(['name' => 'Old Name', 'email' => 'old@example.com']);

        $response = $this->putJson("/api/v1/users/{$target->id}", [
            'name' => 'New Name',
            'email' => 'new@example.com',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('users', ['id' => $target->id, 'name' => 'New Name', 'email' => 'new@example.com']);
    }

    public function test_updating_a_user_without_the_permission_is_forbidden(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $target = User::factory()->forTenant($this->tenant)->create();

        $response = $this->putJson("/api/v1/users/{$target->id}", ['name' => 'New Name', 'email' => null]);

        $response->assertForbidden();
    }

    /**
     * Mirrors UserPolicy::update()'s outranks() guard — the same "cannot act
     * on an equal or better" rule as delete()/resetPassword().
     */
    public function test_an_admin_cannot_update_a_user_they_do_not_outrank(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::USERS_UPDATE]);
        $target = User::factory()->forTenant($this->tenant)->create();
        $powerfulRole = Role::factory()->forTenant($this->tenant)->create(['level' => $admin->roleLevel() + 10]);
        $target->attachRoles($powerfulRole);

        $response = $this->putJson("/api/v1/users/{$target->id}", ['name' => 'New Name', 'email' => null]);

        $response->assertForbidden();
    }

    public function test_a_standalone_users_role_can_be_reassigned_to_a_role_the_actor_outranks(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::USERS_UPDATE, Permissions::ROLES_ASSIGN]);
        $admin->cachedRoles()->first()->update(['level' => 80]);
        $admin->forgetPermissionCache();

        $oldRole = Role::factory()->forTenant($this->tenant)->create(['level' => 30]);
        $newRole = Role::factory()->forTenant($this->tenant)->create(['level' => 40]);
        $target = User::factory()->forTenant($this->tenant)->create();
        $target->attachRoles($oldRole);

        $response = $this->putJson("/api/v1/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role_id' => $newRole->id,
        ]);

        $response->assertOk();
        $target->refresh();
        $this->assertTrue($target->hasRole($newRole->slug));
        $this->assertFalse($target->hasRole($oldRole->slug));
    }

    public function test_a_standalone_users_role_cannot_be_reassigned_to_a_role_the_actor_does_not_outrank(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::USERS_UPDATE, Permissions::ROLES_ASSIGN]);
        $admin->cachedRoles()->first()->update(['level' => 50]);
        $admin->forgetPermissionCache();

        $powerfulRole = Role::factory()->forTenant($this->tenant)->create(['level' => 80]);
        $target = User::factory()->forTenant($this->tenant)->create();

        $response = $this->putJson("/api/v1/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role_id' => $powerfulRole->id,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('role_id');
    }

    /**
     * A student-linked account's role is always forced to Student (see
     * StoreUserRequest) — there is nothing to reassign, so role_id is
     * rejected outright rather than silently ignored.
     */
    public function test_a_student_linked_users_role_id_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::USERS_UPDATE, Permissions::ROLES_ASSIGN]);
        $target = User::factory()->forTenant($this->tenant)->create();
        Student::factory()->create(['user_id' => $target->id]);
        $someRole = Role::factory()->forTenant($this->tenant)->create();

        $response = $this->putJson("/api/v1/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role_id' => $someRole->id,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('role_id');
    }

    /**
     * The escape hatch for AuthService::ensureNoOtherActiveDevice(): an admin
     * clearing a stuck user's live session/tokens so they can sign in
     * elsewhere without needing the device that's still logged in.
     */
    public function test_an_admin_can_force_logout_a_user_they_outrank(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::USERS_UPDATE]);
        $admin->cachedRoles()->first()->update(['level' => 80]);
        $admin->forgetPermissionCache();

        $target = User::factory()->forTenant($this->tenant)->create();
        $target->createToken('phone', ['*'], now()->addDays(30));
        $target->activateSessionLogin();

        $response = $this->postJson("/api/v1/users/{$target->id}/force-logout");

        $response->assertOk();
        $this->assertSame(0, $target->tokens()->count());
        $this->assertFalse($target->fresh()->session_login_active);
    }

    public function test_force_logout_requires_the_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $target = User::factory()->forTenant($this->tenant)->create();

        $response = $this->postJson("/api/v1/users/{$target->id}/force-logout");

        $response->assertForbidden();
    }

    public function test_an_admin_cannot_force_logout_their_own_account(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::USERS_UPDATE]);

        $response = $this->postJson("/api/v1/users/{$admin->id}/force-logout");

        $response->assertForbidden();
    }

    public function test_an_admin_cannot_force_logout_a_user_they_do_not_outrank(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::USERS_UPDATE]);
        $target = User::factory()->forTenant($this->tenant)->create();
        $powerfulRole = Role::factory()->forTenant($this->tenant)->create(['level' => $admin->roleLevel() + 10]);
        $target->attachRoles($powerfulRole);

        $response = $this->postJson("/api/v1/users/{$target->id}/force-logout");

        $response->assertForbidden();
    }
}
