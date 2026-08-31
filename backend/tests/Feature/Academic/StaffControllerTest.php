<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Position;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Auth\UserProvisioningService;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class StaffControllerTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_creating_staff_provisions_a_user_with_the_positions_role(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_CREATE]);
        $accountantRole = Role::factory()->forTenant($this->tenant)->create(['name' => 'Accountant']);
        $position = Position::factory()->create(['name' => 'Accountant', 'role_id' => $accountantRole->id]);

        $response = $this->postJson('/api/v1/staff', [
            'employee_code' => 'S-0001',
            'first_name' => 'John',
            'last_name' => 'Smith',
            'phone' => '012345678',
            'position_id' => $position->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.full_name', 'John Smith');
        $response->assertJsonPath('data.position.name', 'Accountant');
        // Default password is the phone number itself — see
        // UserProvisioningService — since there's no email/SMS channel to
        // deliver a random generated one through.
        $response->assertJsonPath('meta.temporary_password', '012345678');

        $staff = Staff::where('employee_code', 'S-0001')->firstOrFail();
        $this->assertNotNull($staff->user_id);
        $this->assertTrue($staff->user->hasRole($accountantRole->slug));
        $this->assertTrue(Hash::check('012345678', $staff->user->password));
    }

    public function test_creating_staff_with_a_different_position_grants_that_positions_role(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_CREATE]);
        $teacherRole = Role::factory()->forTenant($this->tenant)->create(['name' => 'Teacher', 'slug' => 'teacher']);
        $position = Position::factory()->create(['name' => 'Teaching Assistant', 'role_id' => $teacherRole->id]);

        $response = $this->postJson('/api/v1/staff', [
            'employee_code' => 'S-0002',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '098765432',
            'position_id' => $position->id,
        ]);

        $response->assertCreated();

        $staff = Staff::where('employee_code', 'S-0002')->firstOrFail();
        $this->assertTrue($staff->user->hasRole('teacher'));
    }

    /**
     * Item 8 of the spec: the backend is the only source of truth for the
     * role. A `role`/`role_id` in the payload has to be silently ignored,
     * not merely rejected — StoreStaffRequest doesn't even define the field,
     * so Laravel drops it before validated() ever returns it.
     */
    public function test_submitting_a_role_in_the_staff_payload_has_no_effect(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_CREATE]);
        $staffRole = Role::factory()->forTenant($this->tenant)->create(['name' => 'General Staff', 'slug' => 'staff']);
        $adminRole = Role::factory()->forTenant($this->tenant)->create(['name' => 'Administrator', 'level' => 90]);
        $position = Position::factory()->create(['name' => 'Front Desk', 'role_id' => $staffRole->id]);

        $response = $this->postJson('/api/v1/staff', [
            'employee_code' => 'S-0003',
            'first_name' => 'Attacker',
            'last_name' => 'Test',
            'phone' => '011112222',
            'position_id' => $position->id,
            'role' => 'Administrator',
            'role_id' => $adminRole->id,
        ]);

        $response->assertCreated();

        $staff = Staff::where('employee_code', 'S-0003')->firstOrFail();
        $this->assertTrue($staff->user->hasRole('staff'));
        $this->assertFalse($staff->user->hasRole($adminRole->slug));
    }

    public function test_creation_rolls_back_entirely_when_user_provisioning_fails(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_CREATE]);
        $role = Role::factory()->forTenant($this->tenant)->create();
        $position = Position::factory()->create(['role_id' => $role->id]);

        $this->mock(UserProvisioningService::class)
            ->shouldReceive('provision')
            ->andThrow(new \RuntimeException('simulated provisioning failure'));

        try {
            $this->postJson('/api/v1/staff', [
                'employee_code' => 'S-0004',
                'first_name' => 'Should',
                'last_name' => 'Not Exist',
                'phone' => '010101010',
                'position_id' => $position->id,
            ]);
        } catch (\RuntimeException) {
            // The exception handler re-throws in testing mode by default;
            // what matters here is what got persisted, not the HTTP response.
        }

        $this->assertDatabaseMissing('staff', ['employee_code' => 'S-0004']);
        $this->assertDatabaseMissing('users', ['name' => 'Should Not Exist']);
    }

    public function test_changing_a_staff_members_position_updates_their_role_without_touching_other_roles(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_CREATE, Permissions::STAFF_UPDATE]);
        $accountantRole = Role::factory()->forTenant($this->tenant)->create(['name' => 'Accountant']);
        $hrRole = Role::factory()->forTenant($this->tenant)->create(['name' => 'HR Manager']);
        $accountantPosition = Position::factory()->create(['role_id' => $accountantRole->id]);
        $hrPosition = Position::factory()->create(['role_id' => $hrRole->id]);

        $created = $this->postJson('/api/v1/staff', [
            'employee_code' => 'S-0005',
            'first_name' => 'Mo',
            'last_name' => 'Ver',
            'phone' => '019999999',
            'position_id' => $accountantPosition->id,
        ])->assertCreated();

        $staff = Staff::where('employee_code', 'S-0005')->firstOrFail();
        $extraRole = Role::factory()->forTenant($this->tenant)->create(['name' => 'Extra']);
        $staff->user->attachRoles($extraRole);

        $this->putJson("/api/v1/staff/{$staff->id}", ['position_id' => $hrPosition->id])->assertOk();

        $staff->user->forgetPermissionCache();
        $this->assertTrue($staff->user->hasRole($hrRole->slug));
        $this->assertFalse($staff->user->hasRole($accountantRole->slug));
        $this->assertTrue($staff->user->hasRole($extraRole->slug));
    }

    public function test_deleting_staff_does_not_delete_the_linked_user(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_CREATE, Permissions::STAFF_DELETE]);
        $role = Role::factory()->forTenant($this->tenant)->create();
        $position = Position::factory()->create(['role_id' => $role->id]);

        $this->postJson('/api/v1/staff', [
            'employee_code' => 'S-0006',
            'first_name' => 'Kept',
            'last_name' => 'User',
            'phone' => '017777777',
            'position_id' => $position->id,
        ])->assertCreated();

        $staff = Staff::where('employee_code', 'S-0006')->firstOrFail();
        $userId = $staff->user_id;

        $this->deleteJson("/api/v1/staff/{$staff->id}")->assertNoContent();

        $this->assertSoftDeleted('staff', ['id' => $staff->id]);
        $this->assertDatabaseHas('users', ['id' => $userId, 'deleted_at' => null]);
    }
}
