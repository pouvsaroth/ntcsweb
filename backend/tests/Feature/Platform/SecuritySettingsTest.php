<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Platform\Models\PlatformStaff;
use App\Platform\Models\SecuritySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

/**
 * Settings → Security (as SmartGPS-V3's back office): staff sign-in with or
 * without a code from an authenticator app.
 */
class SecuritySettingsTest extends TestCase
{
    use HasPlatformStaff, RefreshDatabase;

    private function login(PlatformStaff $staff): TestResponse
    {
        return $this->postJson('/api/v1/platform/auth/login', ['email' => $staff->email, 'password' => 'Correct-horse-9']);
    }

    private function require(bool $on): void
    {
        SecuritySettings::current()->update(['require_two_factor' => $on]);
    }

    private function signOut(): void
    {
        $this->postJson('/api/v1/platform/auth/logout');
        Auth::forgetGuards();
    }

    public function test_the_code_is_required_by_default(): void
    {
        $this->assertTrue(SecuritySettings::twoFactorRequired());

        $this->login($this->platformStaff())->assertOk()->assertJsonPath('data.two_factor', 'required');
        $this->assertGuest('staff');
    }

    public function test_switched_off_the_password_alone_signs_in_and_is_recorded(): void
    {
        $this->require(false);
        $staff = $this->platformStaff();

        $this->login($staff)->assertOk()
            ->assertJsonPath('data.two_factor', 'off')
            ->assertJsonPath('data.staff.id', $staff->id);

        $this->assertAuthenticatedAs($staff, 'staff');
        $this->getJson('/api/v1/platform/auth/session')->assertOk();
        $this->getJson('/api/v1/platform/staff')->assertOk();
        $row = \Illuminate\Support\Facades\DB::connection(config('tenancy.database.central_connection'))->table('bo_audit_logs')
            ->where('action', 'auth.signed_in')->where('staff_id', $staff->id)->first();
        $this->assertSame(['two_factor' => 'not required'], json_decode((string) $row?->new_values, true));
        // The authenticator already set up is kept.
        $this->assertTrue($staff->fresh()->hasTwoFactor());
    }

    public function test_switched_off_staff_without_an_authenticator_get_in_and_set_one_up_once_it_is_back_on(): void
    {
        $this->require(false);
        $staff = $this->platformStaff(attributes: ['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);

        $this->login($staff)->assertOk()->assertJsonPath('data.two_factor', 'off');
        $this->getJson('/api/v1/platform/staff')->assertOk();

        // Switched back on: that session ends, and the next sign-in sets one up.
        $this->require(true);
        $this->getJson('/api/v1/platform/staff')->assertUnauthorized();
        $this->signOut();
        $this->login($staff)->assertOk()->assertJsonPath('data.two_factor', 'setup');
    }

    public function test_recovery_codes_need_an_authenticator(): void
    {
        $this->require(false);
        $staff = $this->platformStaff(attributes: ['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);
        $this->login($staff)->assertOk();

        $this->postJson('/api/v1/platform/auth/recovery-codes', ['current_password' => 'Correct-horse-9'])
            ->assertStatus(409)->assertJsonPath('error.code', 'TWO_FACTOR_NOT_SET_UP');
    }

    public function test_only_security_manage_can_see_or_change_it_and_changes_are_audited(): void
    {
        $this->actingAsStaff($this->platformStaff(['platform.staff.read']));
        $this->getJson('/api/v1/platform/settings/security')->assertNotFound();
        $this->putJson('/api/v1/platform/settings/security', ['require_two_factor' => false])->assertNotFound();
        $this->assertTrue(SecuritySettings::twoFactorRequired());

        $this->actingAsStaff($this->platformStaff(['platform.security.manage']));
        $this->getJson('/api/v1/platform/settings/security')->assertOk()->assertJsonPath('data.require_two_factor', true);
        $this->putJson('/api/v1/platform/settings/security', ['require_two_factor' => false])->assertOk()->assertJsonPath('data.require_two_factor', false);
        $this->assertFalse(SecuritySettings::twoFactorRequired());
        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'security.two_factor_requirement_changed']);

        $this->putJson('/api/v1/platform/settings/security', [])->assertUnprocessable();
    }

    public function test_the_owner_role_has_it(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $this->getJson('/api/v1/platform/settings/security')->assertOk();
    }
}
