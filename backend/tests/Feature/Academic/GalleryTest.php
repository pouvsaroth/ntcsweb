<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\GalleryImage;
use App\Models\Tenant;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class GalleryTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_it_uploads_a_gallery_photo(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::GALLERY_CREATE]);

        $response = $this->post('/api/v1/gallery', [
            'image' => UploadedFile::fake()->image('photo.jpg', 1600, 900),
            'caption' => 'Sports Day 2026',
        ], ['Accept' => 'application/json']);

        $response->assertCreated();
        $response->assertJsonPath('data.caption', 'Sports Day 2026');

        $image = GalleryImage::first();
        Storage::disk('public')->assertExists($image->image_path);
        $this->assertStringContainsString("tenants/{$this->tenant->id}/gallery/", $image->image_path);
    }

    public function test_a_non_image_file_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::GALLERY_CREATE]);

        $response = $this->post('/api/v1/gallery', [
            'image' => UploadedFile::fake()->create('not-an-image.pdf', 100),
        ], ['Accept' => 'application/json']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('image');
    }

    public function test_updating_with_a_new_image_deletes_the_old_file(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::GALLERY_CREATE, Permissions::GALLERY_UPDATE]);
        $image = GalleryImage::factory()->create(['image_path' => 'tenants/'.$this->tenant->id.'/gallery/old.jpg']);
        Storage::disk('public')->put($image->image_path, 'fake-old-image-content');

        $response = $this->post("/api/v1/gallery/{$image->id}", [
            '_method' => 'PUT',
            'image' => UploadedFile::fake()->image('new-photo.jpg'),
        ], ['Accept' => 'application/json']);

        $response->assertOk();
        Storage::disk('public')->assertMissing('tenants/'.$this->tenant->id.'/gallery/old.jpg');
        Storage::disk('public')->assertExists($image->fresh()->image_path);
    }

    public function test_updating_without_a_new_image_keeps_the_existing_one(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::GALLERY_UPDATE]);
        $image = GalleryImage::factory()->create();
        $originalPath = $image->image_path;

        $response = $this->putJson("/api/v1/gallery/{$image->id}", ['caption' => 'Updated caption']);

        $response->assertOk();
        $response->assertJsonPath('data.caption', 'Updated caption');
        $this->assertSame($originalPath, $image->fresh()->image_path);
    }

    public function test_soft_deleting_keeps_the_file_but_force_deleting_removes_it(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::GALLERY_DELETE]);
        $image = GalleryImage::factory()->create();
        Storage::disk('public')->put($image->image_path, 'fake-content');

        $this->deleteJson("/api/v1/gallery/{$image->id}")->assertNoContent();
        Storage::disk('public')->assertExists($image->image_path);

        $image->forceDelete();
        Storage::disk('public')->assertMissing($image->image_path);
    }

    public function test_the_public_endpoint_only_returns_active_photos_in_order(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingInTenant($tenant);

        GalleryImage::factory()->create(['sort_order' => 2, 'caption' => 'Second']);
        GalleryImage::factory()->inactive()->create(['sort_order' => 0, 'caption' => 'Hidden']);
        GalleryImage::factory()->create(['sort_order' => 1, 'caption' => 'First']);

        // A real HTTP call re-resolves its tenant from scratch (see
        // TestCase::actingInTenant()'s docblock) — on the central "localhost"
        // test host, the X-Tenant header is what stands in for a real
        // subdomain, exactly as local development without DNS works.
        $response = $this->withHeader('X-Tenant', $tenant->slug)->getJson('/api/v1/public/gallery');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.caption', 'First');
        $response->assertJsonPath('data.1.caption', 'Second');
        $response->assertJsonPath('meta.pagination.type', 'length_aware');
    }

    public function test_the_public_endpoint_is_paginated(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingInTenant($tenant);
        GalleryImage::factory()->count(15)->create();

        $response = $this->withHeader('X-Tenant', $tenant->slug)->getJson('/api/v1/public/gallery?per_page=10');

        $response->assertOk();
        $response->assertJsonCount(10, 'data');
        $response->assertJsonPath('meta.pagination.total', 15);
    }

    public function test_the_download_endpoint_forces_a_save_with_a_named_file(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingInTenant($tenant);
        $image = GalleryImage::factory()->create(['caption' => 'Sports Day 2026']);
        Storage::disk('public')->put($image->image_path, 'fake-image-content');

        $response = $this->withHeader('X-Tenant', $tenant->slug)->get("/api/v1/public/gallery/{$image->id}/download");

        $response->assertOk();
        $response->assertHeader('content-disposition');
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('sports-day-2026', $response->headers->get('content-disposition'));
    }

    public function test_an_inactive_photo_cannot_be_downloaded(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingInTenant($tenant);
        $image = GalleryImage::factory()->inactive()->create();
        Storage::disk('public')->put($image->image_path, 'fake-image-content');

        $response = $this->withHeader('X-Tenant', $tenant->slug)->get("/api/v1/public/gallery/{$image->id}/download");

        $response->assertNotFound();
    }
}
