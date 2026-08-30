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
}
