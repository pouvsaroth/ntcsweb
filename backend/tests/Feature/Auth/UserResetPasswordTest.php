<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class UserResetPasswordTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_a_school_admin_can_reset_a_students_password(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::USERS_UPDATE]);
        $admin->cachedRoles()->first()->update(['level' => 80]);
        $admin->forgetPermissionCache();
        $student = User::factory()->forTenant($this->tenant)->create(['password' => 'old-password']);

        $response = $this->postJson("/api/v1/users/{$student->id}/reset-password", [
            'password' => 'brand-new-password1',
            'password_confirmation' => 'brand-new-password1',
        ]);

        $response->assertOk();
        $this->assertTrue(Hash::check('brand-new-password1', $student->fresh()->password));
    }

    public function test_resetting_a_password_revokes_every_existing_session_of_the_target(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::USERS_UPDATE]);
        $admin->cachedRoles()->first()->update(['level' => 80]);
        $admin->forgetPermissionCache();
        $student = User::factory()->forTenant($this->tenant)->create();
        $student->createToken('test-device');
        $this->assertSame(1, $student->tokens()->count());

        $this->postJson("/api/v1/users/{$student->id}/reset-password", [
            'password' => 'brand-new-password1',
            'password_confirmation' => 'brand-new-password1',
        ])->assertOk();

        $this->assertSame(0, $student->tokens()->count());
    }

    public function test_an_admin_cannot_reset_their_own_password_through_this_endpoint(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::USERS_UPDATE]);
        $admin->cachedRoles()->first()->update(['level' => 80]);
        $admin->forgetPermissionCache();

        $this->postJson("/api/v1/users/{$admin->id}/reset-password", [
            'password' => 'brand-new-password1',
            'password_confirmation' => 'brand-new-password1',
        ])->assertForbidden();
    }

    public function test_an_admin_cannot_reset_the_password_of_an_account_they_do_not_outrank(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::USERS_UPDATE]);
        $admin->cachedRoles()->first()->update(['level' => 40]);
        $admin->forgetPermissionCache();
        $peer = User::factory()->forTenant($this->tenant)->create();
        $peer->attachRoles($admin->cachedRoles()->first());

        $this->postJson("/api/v1/users/{$peer->id}/reset-password", [
            'password' => 'brand-new-password1',
            'password_confirmation' => 'brand-new-password1',
        ])->assertForbidden();
    }

    public function test_an_admin_cannot_reset_a_password_for_another_tenants_user(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::USERS_UPDATE]);
        $admin->cachedRoles()->first()->update(['level' => 80]);
        $admin->forgetPermissionCache();
        $otherTenant = Tenant::factory()->create();
        $outsider = User::factory()->forTenant($otherTenant)->create();

        $this->postJson("/api/v1/users/{$outsider->id}/reset-password", [
            'password' => 'brand-new-password1',
            'password_confirmation' => 'brand-new-password1',
        ])->assertForbidden();
    }

    public function test_the_new_password_must_be_confirmed(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::USERS_UPDATE]);
        $admin->cachedRoles()->first()->update(['level' => 80]);
        $admin->forgetPermissionCache();
        $student = User::factory()->forTenant($this->tenant)->create();

        $response = $this->postJson("/api/v1/users/{$student->id}/reset-password", [
            'password' => 'brand-new-password1',
            'password_confirmation' => 'does-not-match',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('password');
    }

    public function test_resetting_a_password_requires_the_users_update_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $student = User::factory()->forTenant($this->tenant)->create();

        $this->postJson("/api/v1/users/{$student->id}/reset-password", [
            'password' => 'brand-new-password1',
            'password_confirmation' => 'brand-new-password1',
        ])->assertForbidden();
    }
}
