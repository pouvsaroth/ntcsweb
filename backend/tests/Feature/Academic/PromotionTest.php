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

    public function test_it_uploads_a_promotion_image(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROMOTIONS_CREATE]);

        $response = $this->post('/api/v1/promotions', [
            'image' => UploadedFile::fake()->image('banner.jpg', 1600, 900),
            'title' => 'Summer Sale 50% Off',
        ], ['Accept' => 'application/json']);

        $response->assertCreated();
        $response->assertJsonPath('data.title', 'Summer Sale 50% Off');

        $promotion = Promotion::first();
        Storage::disk('public')->assertExists($promotion->image_path);
        $this->assertStringContainsString("tenants/{$this->tenant->id}/promotions/", $promotion->image_path);
    }

    public function test_a_non_image_file_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROMOTIONS_CREATE]);

        $response = $this->post('/api/v1/promotions', [
            'image' => UploadedFile::fake()->create('not-an-image.pdf', 100),
        ], ['Accept' => 'application/json']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('image');
    }

    public function test_updating_with_a_new_image_deletes_the_old_file(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROMOTIONS_CREATE, Permissions::PROMOTIONS_UPDATE]);
        $promotion = Promotion::factory()->create(['image_path' => 'tenants/'.$this->tenant->id.'/promotions/old.jpg']);
        Storage::disk('public')->put($promotion->image_path, 'fake-old-image-content');

        $response = $this->post("/api/v1/promotions/{$promotion->id}", [
            '_method' => 'PUT',
            'image' => UploadedFile::fake()->image('new-banner.jpg'),
        ], ['Accept' => 'application/json']);

        $response->assertOk();
        Storage::disk('public')->assertMissing('tenants/'.$this->tenant->id.'/promotions/old.jpg');
        Storage::disk('public')->assertExists($promotion->fresh()->image_path);
    }

    public function test_updating_without_a_new_image_keeps_the_existing_one(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROMOTIONS_UPDATE]);
        $promotion = Promotion::factory()->create();
        $originalPath = $promotion->image_path;

        $response = $this->putJson("/api/v1/promotions/{$promotion->id}", ['title' => 'Updated title']);

        $response->assertOk();
        $response->assertJsonPath('data.title', 'Updated title');
        $this->assertSame($originalPath, $promotion->fresh()->image_path);
    }

    public function test_soft_deleting_keeps_the_file_but_force_deleting_removes_it(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROMOTIONS_DELETE]);
        $promotion = Promotion::factory()->create();
        Storage::disk('public')->put($promotion->image_path, 'fake-content');

        $this->deleteJson("/api/v1/promotions/{$promotion->id}")->assertNoContent();
        Storage::disk('public')->assertExists($promotion->image_path);

        $promotion->forceDelete();
        Storage::disk('public')->assertMissing($promotion->image_path);
    }

    public function test_viewing_and_managing_promotions_requires_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/v1/promotions')->assertForbidden();
        $this->postJson('/api/v1/promotions', [])->assertForbidden();
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
