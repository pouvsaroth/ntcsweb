<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The shared ERP login domain's single email/phone+password form — see
 * AuthController::loginAcrossTenants()/selectTenant(). No `actingInTenant()`/
 * Host header anywhere here on purpose: the test client's default host is
 * already a central one, exactly like the real ERP domain is.
 */
class CrossTenantLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // See AuthenticationTest's setUp() for why this is needed for a
        // session login to actually set the cookie under test.
        $this->withHeader('Origin', 'http://localhost');
    }

    public function test_a_single_matching_account_logs_in_directly(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'newtech']);
        $user = User::factory()->forTenant($tenant)->create([
            'email' => 'sok@newtech.test',
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'sok@newtech.test',
            'password' => 'correct-password',
        ]);

        $response->assertOk();
        $response->assertJsonMissingPath('data.requires_tenant_selection');
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_same_email_and_password_verified_at_two_schools_requires_a_pick(): void
    {
        $newtech = Tenant::factory()->create(['slug' => 'newtech', 'name' => 'NewTech']);
        $abc = Tenant::factory()->create(['slug' => 'abc', 'name' => 'ABC School']);
        User::factory()->forTenant($newtech)->create(['email' => 'sok@shared.test', 'password' => Hash::make('correct-password')]);
        User::factory()->forTenant($abc)->create(['email' => 'sok@shared.test', 'password' => Hash::make('correct-password')]);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'sok@shared.test',
            'password' => 'correct-password',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.requires_tenant_selection', true);
        $response->assertJsonCount(2, 'data.tenants');
        $this->assertGuest();
    }

    public function test_only_the_account_whose_password_actually_matches_is_offered(): void
    {
        $newtech = Tenant::factory()->create(['slug' => 'newtech']);
        $abc = Tenant::factory()->create(['slug' => 'abc']);
        User::factory()->forTenant($newtech)->create(['email' => 'sok@shared.test', 'password' => Hash::make('correct-password')]);
        User::factory()->forTenant($abc)->create(['email' => 'sok@shared.test', 'password' => Hash::make('a-totally-different-password')]);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'sok@shared.test',
            'password' => 'correct-password',
        ]);

        $response->assertOk();
        $response->assertJsonMissingPath('data.requires_tenant_selection');
        $this->assertAuthenticated();
    }

    public function test_picking_one_of_the_offered_schools_completes_login(): void
    {
        $newtech = Tenant::factory()->create(['slug' => 'newtech']);
        $abc = Tenant::factory()->create(['slug' => 'abc']);
        User::factory()->forTenant($newtech)->create(['email' => 'sok@shared.test', 'password' => Hash::make('correct-password')]);
        $abcUser = User::factory()->forTenant($abc)->create(['email' => 'sok@shared.test', 'password' => Hash::make('correct-password')]);

        $login = $this->postJson('/api/v1/auth/login', [
            'login' => 'sok@shared.test',
            'password' => 'correct-password',
        ]);
        $token = $login->json('data.selection_token');

        $response = $this->postJson('/api/v1/auth/login/select-tenant', [
            'selection_token' => $token,
            'tenant_id' => $abc->id,
        ]);

        $response->assertOk();
        $this->assertAuthenticatedAs($abcUser);
    }

    public function test_a_selection_token_cannot_be_redeemed_for_a_tenant_that_was_not_offered(): void
    {
        $newtech = Tenant::factory()->create(['slug' => 'newtech']);
        $abc = Tenant::factory()->create(['slug' => 'abc']);
        $otherTenant = Tenant::factory()->create(['slug' => 'other']);
        User::factory()->forTenant($newtech)->create(['email' => 'sok@shared.test', 'password' => Hash::make('correct-password')]);
        User::factory()->forTenant($abc)->create(['email' => 'sok@shared.test', 'password' => Hash::make('correct-password')]);

        $login = $this->postJson('/api/v1/auth/login', [
            'login' => 'sok@shared.test',
            'password' => 'correct-password',
        ]);
        $token = $login->json('data.selection_token');

        $response = $this->postJson('/api/v1/auth/login/select-tenant', [
            'selection_token' => $token,
            'tenant_id' => $otherTenant->id,
        ]);

        $response->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_a_selection_token_can_only_be_redeemed_once(): void
    {
        $newtech = Tenant::factory()->create(['slug' => 'newtech']);
        $abc = Tenant::factory()->create(['slug' => 'abc']);
        User::factory()->forTenant($newtech)->create(['email' => 'sok@shared.test', 'password' => Hash::make('correct-password')]);
        User::factory()->forTenant($abc)->create(['email' => 'sok@shared.test', 'password' => Hash::make('correct-password')]);

        $login = $this->postJson('/api/v1/auth/login', [
            'login' => 'sok@shared.test',
            'password' => 'correct-password',
        ]);
        $token = $login->json('data.selection_token');

        $this->postJson('/api/v1/auth/login/select-tenant', ['selection_token' => $token, 'tenant_id' => $abc->id])->assertOk();
        Auth::guard('web')->logout();

        $replay = $this->postJson('/api/v1/auth/login/select-tenant', ['selection_token' => $token, 'tenant_id' => $abc->id]);

        $replay->assertUnprocessable();
    }

    public function test_an_invalid_selection_token_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/auth/login/select-tenant', [
            'selection_token' => 'not-a-real-token',
            'tenant_id' => 1,
        ]);

        $response->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_no_matching_account_anywhere_fails_generically(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'nobody@nowhere.test',
            'password' => 'whatever',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('login');
        $this->assertGuest();
    }

    public function test_an_account_at_a_suspended_school_never_verifies(): void
    {
        $tenant = Tenant::factory()->suspended()->create(['slug' => 'closed']);
        User::factory()->forTenant($tenant)->create([
            'email' => 'sok@closed.test',
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'sok@closed.test',
            'password' => 'correct-password',
        ]);

        $response->assertUnprocessable();
        $this->assertGuest();
    }
}
