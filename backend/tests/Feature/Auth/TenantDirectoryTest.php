<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_active_tenants_for_the_login_school_picker(): void
    {
        Tenant::factory()->create(['name' => 'NewTech Computer School', 'slug' => 'newtech']);
        Tenant::factory()->suspended()->create(['name' => 'Closed School', 'slug' => 'closed']);

        $response = $this->getJson('/api/v1/tenants');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.slug', 'newtech');
    }

    public function test_it_exposes_only_name_and_slug_never_contact_details(): void
    {
        Tenant::factory()->create(['name' => 'NewTech', 'slug' => 'newtech', 'email' => 'secret@newtech.test']);

        $response = $this->getJson('/api/v1/tenants');

        $response->assertOk();
        $response->assertJsonStructure(['data' => [['id', 'slug', 'name']]]);
        $response->assertDontSee('secret@newtech.test');
    }

    public function test_it_can_be_searched_by_name(): void
    {
        Tenant::factory()->create(['name' => 'ABC School', 'slug' => 'abc']);
        Tenant::factory()->create(['name' => 'NewTech Computer School', 'slug' => 'newtech']);

        $response = $this->getJson('/api/v1/tenants?search=newtech');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.slug', 'newtech');
    }
}
