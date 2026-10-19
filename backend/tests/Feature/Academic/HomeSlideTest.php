<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\HomeSlide;
use App\Models\Tenant;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class HomeSlideTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_the_public_endpoint_only_returns_active_slides_in_order(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingInTenant($tenant);

        HomeSlide::factory()->create(['sort_order' => 2, 'title' => 'Second']);
        HomeSlide::factory()->inactive()->create(['sort_order' => 0, 'title' => 'Hidden']);
        HomeSlide::factory()->create(['sort_order' => 1, 'title' => 'First']);

        // A real HTTP call re-resolves its tenant from scratch (see
        // TestCase::actingInTenant()'s docblock) — on the central "localhost"
        // test host, the X-Tenant header is what stands in for a real
        // subdomain, exactly as local development without DNS works.
        $response = $this->withHeader('X-Tenant', $tenant->slug)->getJson('/api/v1/public/home-slides');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.title', 'First');
        $response->assertJsonPath('data.1.title', 'Second');
    }
}
