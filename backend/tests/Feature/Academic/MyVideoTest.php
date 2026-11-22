<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Student;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\TestCase;

/**
 * Student self-service "my videos": every course returned is one the
 * student is actively enrolled in, and its videos are theirs — unless they
 * pay that course by month, then every video is locked. See MyVideoController.
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

    /** An enrollment invoiced with this payment type. */
    private function enrollPaying(Student $student, string $paymentType): Enrollment
    {
        $enrollment = Enrollment::factory()->forStudent($student)->forClass($this->computerEveningClass)
            ->state(['course_package_id' => $this->msWordPackage->id])->create();
        $invoice = Invoice::factory()->create(['student_id' => $student->id, 'payment_type' => $paymentType]);
        InvoiceItem::factory()->forInvoice($invoice)->create(['reference_type' => Enrollment::class, 'reference_id' => $enrollment->id]);

        return $enrollment;
    }

    public function test_a_student_paying_by_month_cannot_watch_any_video(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setUpAcademicCatalog();
        $this->msWordPackage->update(['show_videos' => true]);
        Video::factory()->forPackage($this->msWordPackage)->create(['title' => 'Lesson 1', 'sort_order' => 1]);
        Video::factory()->forPackage($this->msWordPackage)->create(['title' => 'Lesson 2', 'sort_order' => 2]);

        foreach (['monthly' => true, 'monthly_online' => true, 'term' => false, 'video' => false] as $type => $locked) {
            $user = User::factory()->forTenant($this->tenant)->create();
            $this->enrollPaying(Student::factory()->create(['user_id' => $user->id]), $type);

            $course = $this->actingAs($user)->withHeader('X-Tenant', $this->tenant->slug)->getJson('/api/v1/my-videos')->assertOk()->json('data.0');

            // The course still shows, with its video titles, but locked.
            $this->assertSame(['Lesson 1', 'Lesson 2'], array_column($course['videos'], 'title'), $type);
            $this->assertSame($locked ? 'monthly' : null, $course['locked'], $type);
            foreach ($course['videos'] as $video) {
                $this->assertSame($locked, $video['is_locked'], $type);
                $this->assertSame($locked, $video['embed_url'] === null, $type);
            }
        }
    }
}
