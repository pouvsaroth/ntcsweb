<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\CoursePackage;
use App\Models\Enrollment;
use App\Models\Student;
use App\Platform\Models\Plan;
use App\Platform\Models\SchoolSubscription;
use App\Platform\Services\SchoolDatabase;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakeSchoolDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

/**
 * Stage 5d (docs/backoffice/stage-5-billing.md): the plan's student limit.
 */
class StudentLimitTest extends TestCase
{
    use HasAcademicAdmin, HasAcademicCatalog, HasPlatformStaff, RefreshDatabase;

    private function limitTo(?int $max): void
    {
        $plan = Plan::query()->create([
            'code' => 'p-'.fake()->unique()->numberBetween(1, 99999), 'name' => 'Small', 'price' => 10, 'currency' => 'USD',
            'period_months' => 1, 'max_students' => $max, 'modules' => ['academics', 'finance'], 'status' => Plan::STATUS_ACTIVE,
        ]);
        SchoolSubscription::query()->where('tenant_id', $this->tenant->id)->live()->update(['status' => SchoolSubscription::REPLACED]);
        SchoolSubscription::query()->create([
            'tenant_id' => $this->tenant->id, 'plan_id' => $plan->id, 'status' => 'active',
            'start_on' => now()->toDateString(), 'end_on' => now()->addMonth()->toDateString(),
        ]);
    }

    private function enrolled(string $status = Enrollment::STATUS_ACTIVE, ?Student $student = null): Enrollment
    {
        return Enrollment::factory()->forStudent($student ?? Student::factory()->create())->forClass($this->computerEveningClass)->create(['status' => $status]);
    }

    private function enroll(Student $student, ?CoursePackage $package = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => ($package ?? $this->msWordPackage)->id,
            'fee_type' => 'term',
        ]);
    }

    private function signIn(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::ENROLLMENTS_UPDATE, Permissions::ENROLLMENTS_CHANGE_STATUS]);
        $this->setUpAcademicCatalog();
    }

    public function test_a_new_student_cannot_be_enrolled_past_the_limit(): void
    {
        $this->signIn();
        $this->limitTo(2);
        $this->enrolled();
        $this->enrolled(Enrollment::STATUS_NOT_STARTED);
        $this->enrolled(Enrollment::STATUS_COMPLETED); // finished: not counted

        $this->enroll(Student::factory()->create())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('student_limit')
            ->assertJsonPath('errors.student_limit.0', 'Your plan allows 2 active students. Contact us to upgrade your plan.');
    }

    public function test_under_the_limit_or_with_no_limit_enrolling_works(): void
    {
        $this->signIn();
        $this->limitTo(2);
        $this->enrolled();

        $this->enroll(Student::factory()->create())->assertCreated();

        $this->limitTo(null);
        $this->enroll(Student::factory()->create())->assertCreated();
    }

    public function test_an_already_active_student_can_take_another_course_at_the_limit(): void
    {
        $this->signIn();
        $this->limitTo(1);
        $student = Student::factory()->create();
        $other = CoursePackage::factory()->forProgram($this->computerProgram)->create([
            'product_id' => $this->msWordPackage->product_id, 'fee_term' => 20, 'price' => 20,
        ]);
        $this->enroll($student, $other)->assertCreated(); // takes the one place

        $this->enroll($student)->assertCreated(); // same student, second course
        $this->enroll(Student::factory()->create())->assertUnprocessable();
    }

    public function test_moving_an_enrollment_back_to_studying_respects_the_limit(): void
    {
        $this->signIn();
        $this->limitTo(1);
        $this->enrolled();
        $stopped = $this->enrolled(Enrollment::STATUS_STOPPED);

        $this->postJson("/api/v1/enrollments/{$stopped->id}/status", ['status' => Enrollment::STATUS_ACTIVE])
            ->assertUnprocessable()->assertJsonValidationErrors('student_limit');
        $this->patchJson("/api/v1/enrollments/{$stopped->id}", ['status' => Enrollment::STATUS_ACTIVE])
            ->assertUnprocessable()->assertJsonValidationErrors('student_limit');
        $this->assertSame(Enrollment::STATUS_STOPPED, $stopped->fresh()->status);

        // Changing between two counted statuses is never blocked.
        $active = Enrollment::query()->where('status', Enrollment::STATUS_ACTIVE)->firstOrFail();
        $this->postJson("/api/v1/enrollments/{$active->id}/status", ['status' => Enrollment::STATUS_EXAM_READY])->assertOk();
    }

    public function test_a_school_over_its_limit_keeps_everyone(): void
    {
        $this->signIn();
        $this->enrolled();
        $this->enrolled();
        $this->enrolled();
        $this->limitTo(1);

        $this->assertSame(3, Enrollment::query()->where('status', Enrollment::STATUS_ACTIVE)->count());
        $this->enroll(Student::factory()->create())->assertUnprocessable();
    }

    public function test_the_back_office_shows_usage_against_the_limit(): void
    {
        $this->signIn();
        $this->limitTo(300);
        $this->enrolled();
        $student = Student::factory()->create();
        $this->enrolled(Enrollment::STATUS_ACTIVE, $student);
        $this->enrolled(Enrollment::STATUS_EXAM_READY, $student); // one student, counted once

        $this->app->instance(SchoolDatabase::class, new FakeSchoolDatabase);
        $this->actingAsStaff($this->platformStaff());

        $this->getJson("/api/v1/platform/schools/{$this->tenant->id}/billing")->assertOk()
            ->assertJsonPath('data.usage.active', 2)
            ->assertJsonPath('data.usage.limit', 300);
    }
}
