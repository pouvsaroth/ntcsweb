<?php

declare(strict_types=1);

namespace Tests\Feature\Public;

use App\Models\Enrollment;
use App\Models\Student;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\TestCase;

/**
 * The access-control core of the public Video Lesson page: a guest (or a
 * student browsing a course they're not enrolled in) only gets the
 * tenant-wide first 3 videos as free previews; a student actively enrolled
 * in a course sees every video in it. A "locked" video must never carry a
 * playable `embed_url` — the lock is enforced server-side, not just hidden
 * in the UI. See Public\VideoLessonController.
 */
class VideoLessonTest extends TestCase
{
    use HasAcademicAdmin, HasAcademicCatalog, RefreshDatabase;

    /**
     * @return list<Video>
     */
    private function createVideos(int $count): array
    {
        $videos = [];
        for ($i = 1; $i <= $count; $i++) {
            $videos[] = Video::factory()
                ->forTenant($this->tenant)
                ->forPackage($this->msWordPackage)
                ->create(['title' => "Lesson {$i}", 'sort_order' => $i]);
        }

        return $videos;
    }

    public function test_a_course_with_show_videos_off_never_appears(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setUpAcademicCatalog();
        $this->createVideos(1);
        // show_videos defaults to false — deliberately not enabled here.

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)->getJson('/api/v1/public/video-lessons');

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_a_guest_gets_only_the_first_three_videos_unlocked(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setUpAcademicCatalog();
        $this->msWordPackage->update(['show_videos' => true]);
        $this->createVideos(5);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)->getJson('/api/v1/public/video-lessons');

        $response->assertOk();
        $videos = collect($response->json('data.0.videos'));

        $this->assertSame([false, false, false, true, true], $videos->pluck('is_locked')->all());

        // A locked video must never carry a playable URL, regardless of the
        // is_locked flag — the enforcement is server-side.
        $videos->where('is_locked', true)->each(fn ($v) => $this->assertNull($v['embed_url']));
        $videos->where('is_locked', false)->each(fn ($v) => $this->assertNotNull($v['embed_url']));
    }

    public function test_an_enrolled_student_sees_every_video_in_their_course_unlocked(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setUpAcademicCatalog();
        $this->msWordPackage->update(['show_videos' => true]);
        $this->createVideos(5);

        $user = User::factory()->forTenant($this->tenant)->create();
        $student = Student::factory()->forTenant($this->tenant)->create(['user_id' => $user->id]);
        Enrollment::factory()
            ->forTenant($this->tenant)
            ->forStudent($student)
            ->forClass($this->computerEveningClass)
            ->state(['book_id' => null, 'course_package_id' => $this->msWordPackage->id])
            ->create();

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/public/video-lessons');

        $response->assertOk();
        $videos = collect($response->json('data.0.videos'));

        $this->assertTrue($videos->every(fn ($v) => $v['is_locked'] === false));
        $this->assertTrue($videos->every(fn ($v) => $v['embed_url'] !== null));
    }

    public function test_a_logged_in_student_not_enrolled_in_the_course_still_only_gets_the_free_previews(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setUpAcademicCatalog();
        $this->msWordPackage->update(['show_videos' => true]);
        $this->createVideos(5);

        $user = User::factory()->forTenant($this->tenant)->create();
        Student::factory()->forTenant($this->tenant)->create(['user_id' => $user->id]);
        // Deliberately no Enrollment created for this student.

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/public/video-lessons');

        $response->assertOk();
        $videos = collect($response->json('data.0.videos'));

        $this->assertSame([false, false, false, true, true], $videos->pluck('is_locked')->all());
    }

    public function test_an_inactive_video_never_appears_even_to_an_enrolled_student(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setUpAcademicCatalog();
        $this->msWordPackage->update(['show_videos' => true]);

        Video::factory()->forTenant($this->tenant)->forPackage($this->msWordPackage)->inactive()->create(['title' => 'Draft lesson']);
        Video::factory()->forTenant($this->tenant)->forPackage($this->msWordPackage)->create(['title' => 'Published lesson']);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)->getJson('/api/v1/public/video-lessons');

        $response->assertOk();
        $titles = collect($response->json('data.0.videos'))->pluck('title');
        $this->assertTrue($titles->contains('Published lesson'));
        $this->assertFalse($titles->contains('Draft lesson'));
    }
}
