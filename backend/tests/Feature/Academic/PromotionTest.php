<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Promotion;
use App\Models\Tenant;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class PromotionTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_the_public_endpoint_only_returns_active_promotions_in_order(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingInTenant($tenant);

        Promotion::factory()->create(['sort_order' => 2, 'title' => 'Second']);
        Promotion::factory()->inactive()->create(['sort_order' => 0, 'title' => 'Hidden']);
        Promotion::factory()->create(['sort_order' => 1, 'title' => 'First']);

        // A real HTTP call re-resolves its tenant from scratch (see
        // TestCase::actingInTenant()'s docblock) — on the central "localhost"
        // test host, the X-Tenant header is what stands in for a real
        // subdomain, exactly as local development without DNS works.
        $response = $this->withHeader('X-Tenant', $tenant->slug)->getJson('/api/v1/public/promotions');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.title', 'First');
        $response->assertJsonPath('data.1.title', 'Second');
    }
}
