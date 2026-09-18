<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Enrollment;
use App\Models\Student;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\TestCase;

/**
 * Student self-service "my videos": unlike the public Video Lesson page,
 * nothing here is locked — every course returned is one the student is
 * actively enrolled in, so every video in it is already theirs. See
 * MyVideoController.
 */
class MyVideoTest extends TestCase
{
    use HasAcademicAdmin, HasAcademicCatalog, RefreshDatabase;

    public function test_a_student_sees_every_video_in_a_course_they_are_enrolled_in(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setUpAcademicCatalog();
        $this->msWordPackage->update(['show_videos' => true]);
        Video::factory()->forPackage($this->msWordPackage)->create(['title' => 'Lesson 1', 'sort_order' => 1]);
        Video::factory()->forPackage($this->msWordPackage)->create(['title' => 'Lesson 2', 'sort_order' => 2]);

        $user = User::factory()->forTenant($this->tenant)->create();
        $student = Student::factory()->create(['user_id' => $user->id]);
        Enrollment::factory()
            ->forStudent($student)
            ->forClass($this->computerEveningClass)
            ->state(['course_package_id' => $this->msWordPackage->id])
            ->create();

        $response = $this->actingAs($user)->withHeader('X-Tenant', $this->tenant->slug)->getJson('/api/v1/my-videos');

        $response->assertOk();
        $courses = $response->json('data');
        $this->assertCount(1, $courses);
        $this->assertSame($this->msWordPackage->name, $courses[0]['name']);
        $videos = collect($courses[0]['videos']);
        $this->assertSame(['Lesson 1', 'Lesson 2'], $videos->pluck('title')->all());
        $this->assertTrue($videos->every(fn ($v) => $v['embed_url'] !== null));
    }

    public function test_a_student_does_not_see_a_course_they_are_not_enrolled_in(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setUpAcademicCatalog();
        $this->msWordPackage->update(['show_videos' => true]);
        Video::factory()->forPackage($this->msWordPackage)->create();

        $user = User::factory()->forTenant($this->tenant)->create();
        Student::factory()->create(['user_id' => $user->id]);
        // Deliberately no Enrollment created for this student.

        $response = $this->actingAs($user)->withHeader('X-Tenant', $this->tenant->slug)->getJson('/api/v1/my-videos');

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_a_course_with_show_videos_off_never_appears_even_if_enrolled(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setUpAcademicCatalog();
        Video::factory()->forPackage($this->msWordPackage)->create();
        // show_videos defaults to false — deliberately not enabled here.

        $user = User::factory()->forTenant($this->tenant)->create();
        $student = Student::factory()->create(['user_id' => $user->id]);
        Enrollment::factory()
            ->forStudent($student)
            ->forClass($this->computerEveningClass)
            ->state(['course_package_id' => $this->msWordPackage->id])
            ->create();

        $response = $this->actingAs($user)->withHeader('X-Tenant', $this->tenant->slug)->getJson('/api/v1/my-videos');

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_an_account_without_a_student_record_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setUpAcademicCatalog();

        $user = User::factory()->forTenant($this->tenant)->create();

        $response = $this->actingAs($user)->withHeader('X-Tenant', $this->tenant->slug)->getJson('/api/v1/my-videos');

        $response->assertUnprocessable();
    }
}
