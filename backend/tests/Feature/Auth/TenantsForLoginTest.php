<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The shared ERP login domain's "which school is this?" lookup — see
 * AuthController::tenantsForLogin()'s docblock. Public, and deliberately
 * unauthenticated, so no acting-admin setup is needed for any of these.
 */
class TenantsForLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_email_with_one_active_account_returns_one_tenant(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'NewTech', 'slug' => 'newtech']);
        User::factory()->forTenant($tenant)->create(['email' => 'sok@newtech.test']);

        $response = $this->getJson('/api/v1/auth/tenants-for-login?identity=sok@newtech.test');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.slug', 'newtech');
        $response->assertJsonStructure(['data' => [['id', 'slug', 'name']]]);
    }

    public function test_the_same_email_at_two_schools_returns_both_tenants(): void
    {
        $newtech = Tenant::factory()->create(['name' => 'NewTech', 'slug' => 'newtech']);
        $abc = Tenant::factory()->create(['name' => 'ABC School', 'slug' => 'abc']);
        User::factory()->forTenant($newtech)->create(['email' => 'sok@shared.test']);
        User::factory()->forTenant($abc)->create(['email' => 'sok@shared.test']);

        $response = $this->getJson('/api/v1/auth/tenants-for-login?identity=sok@shared.test');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing(
            ['abc', 'newtech'],
            collect($response->json('data'))->pluck('slug')->all(),
        );
    }

    public function test_a_suspended_users_tenant_is_excluded(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'newtech']);
        User::factory()->forTenant($tenant)->suspended()->create(['email' => 'sok@newtech.test']);

        $response = $this->getJson('/api/v1/auth/tenants-for-login?identity=sok@newtech.test');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_a_suspended_tenants_users_are_excluded(): void
    {
        $tenant = Tenant::factory()->suspended()->create(['slug' => 'closed']);
        User::factory()->forTenant($tenant)->create(['email' => 'sok@closed.test']);

        $response = $this->getJson('/api/v1/auth/tenants-for-login?identity=sok@closed.test');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_an_unknown_identity_returns_an_empty_list_not_an_error(): void
    {
        $response = $this->getJson('/api/v1/auth/tenants-for-login?identity=nobody@nowhere.test');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_a_missing_identity_returns_an_empty_list_not_an_error(): void
    {
        $response = $this->getJson('/api/v1/auth/tenants-for-login');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_a_phone_number_resolves_the_same_way_as_an_email(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'newtech']);
        User::factory()->forTenant($tenant)->withPhone('012345678')->create(['email' => 'sok@newtech.test']);

        $response = $this->getJson('/api/v1/auth/tenants-for-login?identity=012345678');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.slug', 'newtech');
    }

    public function test_it_never_exposes_more_than_id_slug_and_name(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'newtech', 'email' => 'secret@newtech.test']);
        User::factory()->forTenant($tenant)->create(['email' => 'sok@newtech.test']);

        $response = $this->getJson('/api/v1/auth/tenants-for-login?identity=sok@newtech.test');

        $response->assertOk();
        $response->assertJsonStructure(['data' => [['id', 'slug', 'name']]]);
        $response->assertDontSee('secret@newtech.test');
    }

    /**
     * The end-to-end point of this lookup: on the shared ERP domain there is
     * no hostname to resolve a tenant from, so the `tenant` slug this
     * endpoint hands back is what makes the *existing*, otherwise-unchanged
     * `/auth/login` succeed — see RequestTenantResolver, which accepts this
     * exact field on a central domain, and AuthController::login(). No
     * `actingInTenant()`/Host header here on purpose — the test client's
     * default host is already a central one, exactly like the real ERP
     * domain would be.
     */
    public function test_the_returned_slug_completes_login_on_a_central_domain(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'newtech']);
        $user = User::factory()->forTenant($tenant)->create([
            'email' => 'sok@newtech.test',
            'password' => Hash::make('correct-password'),
        ]);

        $lookup = $this->getJson('/api/v1/auth/tenants-for-login?identity=sok@newtech.test');
        $lookup->assertOk();
        $slug = $lookup->json('data.0.slug');
        $this->assertSame('newtech', $slug);

        $this->withHeader('Origin', 'http://localhost');
        $response = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'correct-password',
            'tenant' => $slug,
        ]);

        $response->assertOk();
        $this->assertAuthenticatedAs($user);
    }
}
