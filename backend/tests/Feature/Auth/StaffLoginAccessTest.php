<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Staff;
use App\Models\User;
use App\Services\Auth\StaffLoginAccessService;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class StaffLoginAccessTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private function staffWithLogin(string $status = Staff::STATUS_ACTIVE): array
    {
        $user = User::factory()->forTenant($this->tenant)->create();
        $staff = Staff::factory()->withUser($user)->create(['status' => $status]);

        return [$user, $staff];
    }

    private function changeStatus(Staff $staff, string $status): void
    {
        $this->postJson("/api/v1/staff/{$staff->id}/status", [
            'status' => $status,
            'reason' => 'HR update',
            'requested_date' => now()->toDateString(),
            'effective_date' => now()->toDateString(),
        ])->assertOk();
    }

    private function login(User $user): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('X-Tenant', $this->tenant->slug)->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'password',
            'device_name' => 'test-device',
        ]);
    }

    public function test_changing_staff_to_a_non_active_status_blocks_login_and_back_to_active_restores_it(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_CHANGE_STATUS]);
        [$user, $staff] = $this->staffWithLogin();

        $this->changeStatus($staff, Staff::STATUS_RESIGNED);
        $this->assertSame(User::STATUS_INACTIVE, $user->fresh()->status);

        $response = $this->login($user->fresh());
        $response->assertUnprocessable()->assertJsonValidationErrors('login');
        $this->assertSame(__('auth.inactive'), $response->json('errors.login.0'));

        $this->changeStatus($staff, Staff::STATUS_ACTIVE);
        $this->assertSame(User::STATUS_ACTIVE, $user->fresh()->status);
        $this->login($user->fresh())->assertOk();
    }

    public function test_every_status_where_the_staff_member_has_left_blocks_login(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_CHANGE_STATUS]);

        foreach (array_diff(Staff::STATUSES_MANAGEABLE, StaffLoginAccessService::CAN_LOG_IN) as $status) {
            [$user, $staff] = $this->staffWithLogin();
            $this->changeStatus($staff, $status);

            $this->assertSame(User::STATUS_INACTIVE, $user->fresh()->status, $status);
        }
    }

    public function test_probation_and_on_leave_staff_keep_their_login(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_CHANGE_STATUS]);

        foreach ([Staff::STATUS_PROBATION, Staff::STATUS_ON_LEAVE] as $status) {
            [$user, $staff] = $this->staffWithLogin();
            $this->changeStatus($staff, $status);

            $this->assertSame(User::STATUS_ACTIVE, $user->fresh()->status, $status);
        }

        // Back from Resigned to Probation unlocks it again too.
        [$user, $staff] = $this->staffWithLogin();
        $this->changeStatus($staff, Staff::STATUS_RESIGNED);
        $this->changeStatus($staff, Staff::STATUS_PROBATION);
        $this->assertSame(User::STATUS_ACTIVE, $user->fresh()->status);
    }

    public function test_an_invited_or_suspended_account_is_left_alone(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_CHANGE_STATUS]);
        [$invited, $invitedStaff] = $this->staffWithLogin(Staff::STATUS_ON_LEAVE);
        $invited->forceFill(['status' => User::STATUS_INVITED])->save();
        [$suspended, $suspendedStaff] = $this->staffWithLogin(Staff::STATUS_ON_LEAVE);
        $suspended->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->changeStatus($invitedStaff, Staff::STATUS_ACTIVE);
        $this->changeStatus($suspendedStaff, Staff::STATUS_ACTIVE);

        $this->assertSame(User::STATUS_INVITED, $invited->fresh()->status);
        $this->assertSame(User::STATUS_SUSPENDED, $suspended->fresh()->status);
    }

    public function test_the_daily_sync_locks_staff_who_were_already_non_active(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$user, $staff] = $this->staffWithLogin();
        // Changed without the model hook, like rows from before this rule.
        Staff::query()->whereKey($staff->id)->toBase()->update(['status' => Staff::STATUS_TERMINATED]);
        $this->assertSame(User::STATUS_ACTIVE, $user->fresh()->status);

        $this->artisan('staff:sync-login-access')->assertSuccessful();

        $this->assertSame(User::STATUS_INACTIVE, $user->fresh()->status);
    }
}
