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
}
