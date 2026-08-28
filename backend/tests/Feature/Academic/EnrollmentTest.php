<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Tenant;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class EnrollmentTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_it_enrolls_a_student_into_a_class(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $student = Student::factory()->create();
        $class = SchoolClass::factory()->create();

        $response = $this->postJson('/api/v1/enrollments', [
            'student_id' => $student->id,
            'class_id' => $class->id,
            'enrolled_at' => '2026-01-15',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.student.id', $student->id);
        $response->assertJsonPath('data.class.id', $class->id);
    }

    public function test_a_student_cannot_be_enrolled_in_the_same_class_twice(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $student = Student::factory()->create();
        $class = SchoolClass::factory()->create();
        Enrollment::factory()->forStudent($student)->forClass($class)->create();

        $response = $this->postJson('/api/v1/enrollments', [
            'student_id' => $student->id,
            'class_id' => $class->id,
            'enrolled_at' => '2026-01-15',
        ]);

        $response->assertUnprocessable();
    }

    public function test_the_same_student_may_enroll_in_the_class_again_at_a_different_school(): void
    {
        $otherTenant = Tenant::factory()->create();
        $this->createForOtherTenant(function () use ($otherTenant) {
            $student = Student::factory()->forTenant($otherTenant)->create();
            $class = SchoolClass::factory()->forTenant($otherTenant)->create();
            Enrollment::factory()->forTenant($otherTenant)->forStudent($student)->forClass($class)->create();
        });

        // Different school entirely — its own student_id/class_id numbering
        // could coincidentally collide with the other tenant's, and must not.
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $myStudent = Student::factory()->create();
        $myClass = SchoolClass::factory()->create();

        $response = $this->postJson('/api/v1/enrollments', [
            'student_id' => $myStudent->id,
            'class_id' => $myClass->id,
            'enrolled_at' => '2026-01-15',
        ]);

        $response->assertCreated();
    }

    public function test_an_enrollment_cannot_reference_a_student_from_another_tenant(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $foreignStudent = $this->createForOtherTenant(fn () => Student::factory()->forTenant(Tenant::factory()->create())->create());
        $class = SchoolClass::factory()->create();

        $response = $this->postJson('/api/v1/enrollments', [
            'student_id' => $foreignStudent->id,
            'class_id' => $class->id,
            'enrolled_at' => '2026-01-15',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('student_id');
    }

    public function test_it_updates_enrollment_status(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_UPDATE]);
        $enrollment = Enrollment::factory()->create(['status' => Enrollment::STATUS_ACTIVE]);

        $response = $this->putJson("/api/v1/enrollments/{$enrollment->id}", ['status' => Enrollment::STATUS_DROPPED]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'dropped');
    }
}
