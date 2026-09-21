<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantDomain;
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

    private function roleFor(string $slug): Role
    {
        return Role::factory()->forTenant($this->tenant)->system()->create([
            'slug' => $slug,
            'name' => $slug,
            'level' => Role::LEVELS[$slug],
        ]);
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

    /**
     * AdminHeader.vue's "Go to website" link needs an absolute URL now that
     * the admin app is a separate origin from the school's own public
     * website — see Tenant::hostname() (falls back to the slug subdomain
     * when no custom domain is set) and AdminHeader.vue.
     */
    public function test_me_exposes_the_tenants_own_hostname(): void
    {
        $user = User::factory()->forTenant($this->tenant)->create();

        $this->actingAsTenantUser($user);
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertOk();
        $response->assertJsonPath('meta.tenant.hostname', 'newtech.'.config('tenancy.root_domain'));
    }

    /**
     * Regression test: `me()` must eager-load the tenant's primaryDomain
     * relation itself — Tenant::hostname() silently falls back to the slug
     * subdomain whenever that relation isn't already loaded on the model,
     * and the tenant resolved onto TenantContext never carries it by default.
     */
    public function test_me_exposes_a_custom_domain_when_one_is_the_tenants_primary(): void
    {
        TenantDomain::create([
            'tenant_id' => $this->tenant->id,
            'hostname' => 'newtechkh.com',
            'type' => TenantDomain::TYPE_CUSTOM,
            'is_primary' => true,
            'verified_at' => now(),
        ]);

        $user = User::factory()->forTenant($this->tenant)->create();

        $this->actingAsTenantUser($user);
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertOk();
        $response->assertJsonPath('meta.tenant.hostname', 'newtechkh.com');
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
     * The per-role concurrent-device limit (AuthService::ensureNoOtherActiveDevice(),
     * User::maxConcurrentDevices()): a Student may only ever have one device
     * signed in, so a still-unexpired token from a previous login blocks a
     * new one for a different device, with the exact message the user is
     * told to act on.
     */
    public function test_a_student_is_blocked_by_a_second_device(): void
    {
        $user = User::factory()->forTenant($this->tenant)->create(['password' => Hash::make('correct-password')]);
        $user->attachRoles($this->roleFor(Role::STUDENT));
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
     * Every role other than Student/School Admin (Teacher, Staff, or an
     * account with no role at all — see User::maxConcurrentDevices()) may
     * have up to three devices signed in at once, mixed freely across the
     * token and session transports.
     */
    public function test_a_non_student_non_admin_user_may_have_up_to_three_active_devices(): void
    {
        $user = User::factory()->forTenant($this->tenant)->create(['password' => Hash::make('correct-password')]);
        $user->createToken('phone', ['*'], now()->addDays(30));
        $user->recordLoginSession('existing-browser-session', '127.0.0.1', 'PHPUnit');

        $this->actingInTenant($this->tenant);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'correct-password',
            'device_name' => 'laptop',
        ]);

        $response->assertOk();
        $this->assertSame(2, $user->tokens()->count());
    }

    /**
     * The fourth device is where that same allowance runs out.
     */
    public function test_a_non_student_non_admin_user_is_blocked_by_a_fourth_device(): void
    {
        $user = User::factory()->forTenant($this->tenant)->create(['password' => Hash::make('correct-password')]);
        $user->createToken('phone', ['*'], now()->addDays(30));
        $user->createToken('tablet', ['*'], now()->addDays(30));
        $user->recordLoginSession('existing-browser-session', '127.0.0.1', 'PHPUnit');

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
        $this->assertSame(2, $user->tokens()->count());
    }

    /**
     * School Admin has no concurrent-device limit at all (see
     * User::maxConcurrentDevices()) — they're the ones who clear it for
     * everyone else, and routinely need their own account open on more than
     * one device at once.
     */
    public function test_a_school_admin_is_not_blocked_by_a_still_active_device(): void
    {
        $user = User::factory()->forTenant($this->tenant)->create(['password' => Hash::make('correct-password')]);
        $user->attachRoles($this->roleFor(Role::SCHOOL_ADMIN));
        $user->createToken('phone', ['*'], now()->addDays(30));

        $this->actingInTenant($this->tenant);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'correct-password',
            'device_name' => 'laptop',
        ]);

        $response->assertOk();
        $this->assertSame(2, $user->tokens()->count());
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
     * actually uses, and for a Student specifically — their one-device
     * allowance means a single still-open browser session (a UserSession
     * row nobody signed out of) blocks a new session login on its own. Set
     * directly rather than via a real login request, since a fresh test
     * user never has one left over on its own.
     */
    public function test_a_student_is_blocked_by_a_still_active_session(): void
    {
        $user = User::factory()->forTenant($this->tenant)->create(['password' => Hash::make('correct-password')]);
        $user->attachRoles($this->roleFor(Role::STUDENT));
        $user->recordLoginSession('existing-browser-session', '127.0.0.1', 'PHPUnit');

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
     * Logging out deletes the UserSession row outright, so the device is
     * immediately free to log back in — the whole point of the rule is "log
     * out first," not "wait." Uses a Student (one-device limit) so the
     * assertion is only true if the slot really was freed by the logout.
     *
     * The session cookie is carried forward by hand between requests — the
     * test client doesn't do this automatically the way a real browser
     * would — so that logout() sees the *same* session id recordLoginSession()
     * stored, rather than a fresh, unrelated one.
     */
    public function test_logging_out_a_session_immediately_frees_its_device_slot(): void
    {
        $user = User::factory()->forTenant($this->tenant)->create(['password' => Hash::make('correct-password')]);
        $user->attachRoles($this->roleFor(Role::STUDENT));

        $this->actingInTenant($this->tenant);

        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'correct-password',
        ])->assertOk();

        // postJson() sends no cookies at all unless withCredentials() opts in
        // (mirroring a real fetch() call needing credentials: 'include'), and
        // withCookie() takes the plain value — the test harness re-encrypts
        // it the same way a real EncryptCookies response cookie would be.
        $sessionCookie = $loginResponse->getCookie(config('session.cookie'));

        $this->withCredentials()
            ->withCookie($sessionCookie->getName(), $sessionCookie->getValue())
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertOk();
        $this->assertSame(1, $user->loginSessions()->count());
    }
}
