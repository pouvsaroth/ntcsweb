<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'newtech']);

        // Sanctum's EnsureFrontendRequestsAreStateful only attaches session
        // middleware (and therefore issues the auth cookie) to requests it
        // recognises as coming from a first-party SPA, which it decides purely
        // from the Origin/Referer header matching sanctum.stateful. A real
        // browser always sends Origin on a fetch/XHR POST; simulating that here
        // is what makes session login exercisable at all in tests.
        //
        // Deliberately the bare host with no port: SANCTUM_STATEFUL_DOMAINS
        // lists a specific dev-server port (currently 5299) that has already
        // changed once (a local Windows port-reservation conflict) and can
        // again — "localhost" with no port is unconditionally in the list via
        // TENANCY_CENTRAL_DOMAINS/config default, so this assertion doesn't
        // silently start failing the next time the port does.
        $this->withHeader('Origin', 'http://localhost');
    }

    public function test_a_user_can_log_in_with_correct_credentials_via_session(): void
    {
        $user = User::factory()->forTenant($this->tenant)->create(['password' => Hash::make('correct-password')]);

        $this->actingInTenant($this->tenant);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.user.id', $user->id);
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_user_can_log_in_and_receive_a_bearer_token(): void
    {
        $user = User::factory()->forTenant($this->tenant)->create(['password' => Hash::make('correct-password')]);

        $this->actingInTenant($this->tenant);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'correct-password',
            'device_name' => 'test-device',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['token', 'token_type']]);
    }

    public function test_a_user_can_log_in_with_a_phone_number_instead_of_email(): void
    {
        $user = User::factory()->forTenant($this->tenant)->withPhone('012 345 678')->create(['password' => Hash::make('correct-password')]);

        $this->actingInTenant($this->tenant);

        // Deliberately formatted differently than storage (spaces vs none) —
        // PhoneNumber::normalize() has to make these compare equal.
        $response = $this->postJson('/api/v1/auth/login', [
            'login' => '012-345-678',
            'password' => 'correct-password',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.user.id', $user->id);
    }

    /**
     * A numeric fragment of an email address (e.g. a year in a username)
     * must never accidentally match someone else's phone number.
     */
    public function test_a_short_numeric_looking_login_does_not_match_an_unrelated_phone_number(): void
    {
        User::factory()->forTenant($this->tenant)->withPhone('012345')->create(['password' => Hash::make('correct-password')]);

        $this->actingInTenant($this->tenant);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => '12345',
            'password' => 'correct-password',
        ]);

        $response->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->forTenant($this->tenant)->create(['password' => Hash::make('correct-password')]);

        $this->actingInTenant($this->tenant);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable();
        $this->assertGuest();
    }

    /**
     * The security property that matters most: the error for "no such user"
     * and "wrong password" must be identical, or the endpoint becomes an
     * account-enumeration oracle.
     */
    public function test_login_error_message_does_not_reveal_whether_the_account_exists(): void
    {
        $user = User::factory()->forTenant($this->tenant)->create(['password' => Hash::make('correct-password')]);

        $this->actingInTenant($this->tenant);

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'wrong-password',
        ])->json('errors.login.0');

        $unknownUser = $this->postJson('/api/v1/auth/login', [
            'login' => 'nobody@school.test',
            'password' => 'whatever',
        ])->json('errors.login.0');

        $this->assertSame($wrongPassword, $unknownUser);
    }

    public function test_a_suspended_user_cannot_log_in(): void
    {
        $user = User::factory()->forTenant($this->tenant)->suspended()->create(['password' => Hash::make('correct-password')]);

        $this->actingInTenant($this->tenant);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertUnprocessable();
        $this->assertGuest();
    }

    /**
     * The same credentials must not authenticate a user against a school they
     * do not belong to — the lookup itself is tenant-scoped, not just the
     * post-auth check.
     */
    public function test_login_does_not_succeed_against_a_different_tenant(): void
    {
        $otherTenant = Tenant::factory()->create(['slug' => 'abcschool']);
        $user = User::factory()->forTenant($this->tenant)->create(['password' => Hash::make('correct-password')]);

        $this->actingInTenant($otherTenant);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_a_suspended_users_live_session_stops_working_immediately(): void
    {
        $user = User::factory()->forTenant($this->tenant)->create();

        $this->actingAsTenantUser($user);
        $this->getJson('/api/v1/auth/me')->assertOk();

        $user->update(['status' => User::STATUS_SUSPENDED]);

        $response = $this->getJson('/api/v1/auth/me');

        $response->assertForbidden();
        $response->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    public function test_logout_revokes_the_current_token_only(): void
    {
        $user = User::factory()->forTenant($this->tenant)->create();
        $tokenA = $user->createToken('device-a')->plainTextToken;
        $user->createToken('device-b');

        $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->actingInTenant($this->tenant)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertSame(1, $user->tokens()->count());
    }

    /**
     * The one-device rule (AuthService::ensureNoOtherActiveDevice()): a
     * still-unexpired token from a previous login blocks a new one for a
     * different device, with the exact message the user is told to act on.
     */
    public function test_a_second_token_login_is_blocked_while_the_first_is_still_valid(): void
    {
        $user = User::factory()->forTenant($this->tenant)->create(['password' => Hash::make('correct-password')]);
        $user->createToken('phone', ['*'], now()->addDays(30));

        $this->actingInTenant($this->tenant);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'correct-password',
            'device_name' => 'laptop',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonPath(
            'errors.login.0',
            'You are logging in another device, please logout first or you can ask admin for help.',
        );
        $this->assertSame(1, $user->tokens()->count());
    }

    /**
     * Re-authenticating from the *same* device (matched by device_name) is
     * not "another device" — tokenResponse() replaces that token exactly as
     * it did before this rule existed (e.g. reinstalling the mobile app).
     */
    public function test_reauthenticating_the_same_named_device_is_not_blocked(): void
    {
        $user = User::factory()->forTenant($this->tenant)->create(['password' => Hash::make('correct-password')]);
        $user->createToken('phone', ['*'], now()->addDays(30));

        $this->actingInTenant($this->tenant);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'correct-password',
            'device_name' => 'phone',
        ]);

        $response->assertOk();
        $this->assertSame(1, $user->tokens()->count());
    }

    /**
     * An expired token left behind by a past login is not "still active" —
     * nothing to log out of, so a new login is not blocked by it.
     */
    public function test_an_expired_token_does_not_block_a_new_login(): void
    {
        $user = User::factory()->forTenant($this->tenant)->create(['password' => Hash::make('correct-password')]);
        $user->createToken('old-phone', ['*'], now()->subDay());

        $this->actingInTenant($this->tenant);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'correct-password',
            'device_name' => 'laptop',
        ]);

        $response->assertOk();
    }

    /**
     * Mirrors the token case above, but for the session transport the SPA
     * actually uses: session_login_active still true from a previous login
     * nobody signed out of blocks a new session login. Set directly rather
     * than via a real login request, since a fresh test user never has one
     * left over on its own.
     */
    public function test_a_session_login_is_blocked_while_another_session_is_still_active(): void
    {
        $user = User::factory()->forTenant($this->tenant)->create(['password' => Hash::make('correct-password')]);
        $user->activateSessionLogin();

        $this->actingInTenant($this->tenant);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonPath(
            'errors.login.0',
            'You are logging in another device, please logout first or you can ask admin for help.',
        );
    }

    /**
     * Logging out deletes the session row outright (Store::invalidate()
     * destroys it via the handler), so the device is immediately free to log
     * back in — the whole point of the rule is "log out first," not "wait."
     */
    public function test_logging_out_a_session_immediately_clears_the_one_device_lock(): void
    {
        $user = User::factory()->forTenant($this->tenant)->create(['password' => Hash::make('correct-password')]);

        $this->actingInTenant($this->tenant);

        $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'correct-password',
        ])->assertOk();

        $this->postJson('/api/v1/auth/logout')->assertOk();

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertOk();
    }
}
