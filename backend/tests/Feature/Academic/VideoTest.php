<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Video;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\TestCase;

class VideoTest extends TestCase
{
    use HasAcademicAdmin, HasAcademicCatalog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_an_admin_can_create_a_video_from_a_youtube_link(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::VIDEOS_CREATE]);
        $this->setUpAcademicCatalog();

        $response = $this->postJson('/api/v1/videos', [
            'course_package_id' => $this->msWordPackage->id,
            'title' => 'Lesson 1: Getting Started',
            'description' => 'An introduction to MS Word.',
            'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'sort_order' => 1,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.title', 'Lesson 1: Getting Started');
        $response->assertJsonPath('data.thumbnail_url', 'https://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg');
        $response->assertJsonPath('data.embed_url', 'https://www.youtube.com/embed/dQw4w9WgXcQ');
    }

    public function test_a_non_youtube_link_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::VIDEOS_CREATE]);
        $this->setUpAcademicCatalog();

        $response = $this->postJson('/api/v1/videos', [
            'course_package_id' => $this->msWordPackage->id,
            'title' => 'Lesson 1',
            'video_url' => 'https://vimeo.com/12345',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('video_url');
    }

    public function test_a_custom_thumbnail_overrides_the_youtube_default(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::VIDEOS_CREATE]);
        $this->setUpAcademicCatalog();

        $response = $this->post('/api/v1/videos', [
            'course_package_id' => $this->msWordPackage->id,
            'title' => 'Lesson 1',
            'video_url' => 'https://youtu.be/dQw4w9WgXcQ',
            'thumbnail' => UploadedFile::fake()->image('cover.jpg'),
        ], ['Accept' => 'application/json']);

        $response->assertCreated();
        $this->assertStringNotContainsString('img.youtube.com', $response->json('data.thumbnail_url'));

        $video = Video::findOrFail($response->json('data.id'));
        Storage::disk('public')->assertExists($video->thumbnail_path);
    }

    public function test_creating_a_video_requires_the_create_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::VIDEOS_VIEW]);
        $this->setUpAcademicCatalog();

        $response = $this->postJson('/api/v1/videos', [
            'course_package_id' => $this->msWordPackage->id,
            'title' => 'Lesson 1',
            'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ]);

        $response->assertForbidden();
    }

    public function test_deleting_a_video_is_soft_and_recoverable(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::VIDEOS_CREATE, Permissions::VIDEOS_DELETE]);
        $this->setUpAcademicCatalog();

        $video = Video::factory()->forTenant($this->tenant)->forPackage($this->msWordPackage)->create();

        $this->deleteJson("/api/v1/videos/{$video->id}")->assertNoContent();

        $this->assertSoftDeleted('videos', ['id' => $video->id]);
    }
}
