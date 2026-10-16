<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Platform\Models\PlatformStaff;
use App\Platform\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

class PlatformSignInTest extends TestCase
{
    use HasPlatformStaff, RefreshDatabase;

    private function login(PlatformStaff $staff, string $password = 'Correct-horse-9'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/platform/auth/login', ['email' => $staff->email, 'password' => $password]);
    }

    public function test_password_then_code_signs_in(): void
    {
        $staff = $this->platformStaff();

        $this->login($staff)->assertOk()->assertJsonPath('data.two_factor', 'required');

        // A password alone reaches nothing.
        $this->getJson('/api/v1/platform/auth/session')->assertUnauthorized();

        $this->postJson('/api/v1/platform/auth/two-factor', ['code' => $this->currentCode($staff)])
            ->assertOk()
            ->assertJsonPath('data.staff.email', $staff->email)
            ->assertJsonPath('data.must_change_password', false);

        $this->getJson('/api/v1/platform/auth/session')->assertOk();
        $this->assertDatabaseHas('bo_audit_logs', ['staff_id' => $staff->id, 'action' => 'auth.signed_in']);
        $this->assertNotNull($staff->fresh()->last_login_at);
    }

    public function test_wrong_password_unknown_email_and_disabled_account_all_look_the_same(): void
    {
        $staff = $this->platformStaff();
        $disabled = $this->platformStaff(attributes: ['status' => PlatformStaff::STATUS_DISABLED]);

        $wrong = $this->login($staff, 'nope')->assertUnprocessable()->json('errors');
        $unknown = $this->postJson('/api/v1/platform/auth/login', ['email' => 'nobody@example.com', 'password' => 'x'])
            ->assertUnprocessable()->json('errors');
        $off = $this->login($disabled)->assertUnprocessable()->json('errors');

        $this->assertSame($wrong, $unknown);
        $this->assertSame($wrong, $off);
    }

    public function test_a_wrong_code_does_not_sign_in(): void
    {
        $staff = $this->platformStaff();
        $this->login($staff);

        $this->postJson('/api/v1/platform/auth/two-factor', ['code' => '000000'])->assertUnprocessable();
        $this->getJson('/api/v1/platform/auth/session')->assertUnauthorized();
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        $staff = $this->platformStaff();
        $code = $this->currentCode($staff);

        $this->login($staff);
        $this->postJson('/api/v1/platform/auth/two-factor', ['code' => $code])->assertOk();
        $this->postJson('/api/v1/platform/auth/logout')->assertNoContent();

        $this->login($staff);
        $this->postJson('/api/v1/platform/auth/two-factor', ['code' => $code])->assertUnprocessable();
    }

    public function test_the_code_step_without_a_password_first_is_refused(): void
    {
        $staff = $this->platformStaff();

        $this->postJson('/api/v1/platform/auth/two-factor', ['code' => $this->currentCode($staff)])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'SIGN_IN_EXPIRED');
    }

    public function test_the_password_step_expires(): void
    {
        $staff = $this->platformStaff();
        $this->login($staff);

        $this->travel(6)->minutes();

        $this->postJson('/api/v1/platform/auth/two-factor', ['code' => $this->currentCode($staff)])->assertUnauthorized();
    }

    public function test_first_sign_in_enrols_two_factor_and_shows_recovery_codes_once(): void
    {
        $staff = $this->platformStaff(attributes: ['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);

        $this->login($staff)->assertOk()->assertJsonPath('data.two_factor', 'setup');

        $secret = $this->postJson('/api/v1/platform/auth/two-factor/setup')
            ->assertOk()
            ->assertJsonPath('data.otpauth_uri', fn (string $uri) => str_starts_with($uri, 'otpauth://totp/'))
            ->json('data.secret');

        $this->postJson('/api/v1/platform/auth/two-factor/confirm', ['code' => '000000'])->assertUnprocessable();

        $codes = $this->postJson('/api/v1/platform/auth/two-factor/confirm', [
            'code' => Totp::codeAt($secret, Totp::step(now()->getTimestamp())),
        ])->assertOk()->json('data.recovery_codes');

        $this->assertCount(8, $codes);
        $this->assertTrue($staff->fresh()->hasTwoFactor());
        $this->getJson('/api/v1/platform/auth/session')->assertOk()->assertJsonPath('data.recovery_codes_left', 8);
    }

    public function test_a_password_alone_cannot_replace_an_enrolled_second_factor(): void
    {
        $staff = $this->platformStaff();
        $secret = $staff->two_factor_secret;
        $this->login($staff);

        $this->postJson('/api/v1/platform/auth/two-factor/setup')->assertStatus(409);
        $this->assertSame($secret, $staff->fresh()->two_factor_secret);
    }

    public function test_a_recovery_code_works_once(): void
    {
        $staff = $this->platformStaff(attributes: ['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);
        $this->login($staff);
        $secret = $this->postJson('/api/v1/platform/auth/two-factor/setup')->json('data.secret');
        $codes = $this->postJson('/api/v1/platform/auth/two-factor/confirm', [
            'code' => Totp::codeAt($secret, Totp::step(now()->getTimestamp())),
        ])->json('data.recovery_codes');
        $this->postJson('/api/v1/platform/auth/logout');

        $this->login($staff);
        $this->postJson('/api/v1/platform/auth/two-factor', ['code' => strtoupper($codes[0])])->assertOk();
        $this->postJson('/api/v1/platform/auth/logout');

        $this->login($staff);
        $this->postJson('/api/v1/platform/auth/two-factor', ['code' => $codes[0]])->assertUnprocessable();
        $this->assertDatabaseHas('bo_audit_logs', ['staff_id' => $staff->id, 'action' => 'auth.recovery_code_used']);
    }

    public function test_password_attempts_are_rate_limited(): void
    {
        $staff = $this->platformStaff();

        foreach (range(1, 5) as $_) {
            $this->login($staff, 'wrong')->assertUnprocessable();
        }

        $this->login($staff)->assertStatus(429);
    }

    public function test_a_temporary_password_blocks_everything_until_changed(): void
    {
        $staff = $this->platformStaff(attributes: ['must_change_password' => true]);
        $this->actingAsStaff($staff);

        $this->getJson('/api/v1/platform/staff')->assertStatus(409)->assertJsonPath('error.code', 'PASSWORD_CHANGE_REQUIRED');
        $this->getJson('/api/v1/platform/auth/session')->assertOk()->assertJsonPath('data.must_change_password', true);

        $this->putJson('/api/v1/platform/auth/password', [
            'current_password' => 'Correct-horse-9',
            'password' => 'Correct-horse-9',
            'password_confirmation' => 'Correct-horse-9',
        ])->assertUnprocessable();

        $this->putJson('/api/v1/platform/auth/password', [
            'current_password' => 'Correct-horse-9',
            'password' => 'A-new-password-42',
            'password_confirmation' => 'A-new-password-42',
        ])->assertOk()->assertJsonPath('data.must_change_password', false);

        $this->getJson('/api/v1/platform/staff')->assertOk();
        $this->assertDatabaseHas('bo_audit_logs', ['staff_id' => $staff->id, 'action' => 'auth.password_changed']);
    }

    public function test_disabling_or_resetting_two_factor_ends_a_live_session(): void
    {
        $staff = $this->platformStaff();
        $this->actingAsStaff($staff)->getJson('/api/v1/platform/auth/session')->assertOk();

        $staff->forceFill(['two_factor_confirmed_at' => null])->save();
        $this->getJson('/api/v1/platform/auth/session')->assertUnauthorized();

        $other = $this->platformStaff();
        $this->actingAsStaff($other);
        $other->forceFill(['status' => PlatformStaff::STATUS_DISABLED])->save();
        $this->getJson('/api/v1/platform/auth/session')->assertUnauthorized();
    }

    public function test_the_create_staff_command_makes_an_owner_who_must_enrol_and_change_password(): void
    {
        $this->artisan('platform:create-staff', ['email' => 'Owner@Example.com', 'name' => 'First Owner'])
            ->expectsOutputToContain('Temporary password')
            ->assertSuccessful();

        $staff = PlatformStaff::query()->where('email', 'owner@example.com')->firstOrFail();
        $this->assertTrue($staff->isOwner());
        $this->assertTrue($staff->must_change_password);
        $this->assertFalse($staff->hasTwoFactor());

        $this->artisan('platform:create-staff', ['email' => 'owner@example.com', 'name' => 'Again'])->assertFailed();
    }
}
