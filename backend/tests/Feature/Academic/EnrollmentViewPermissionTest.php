<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Student;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\TestCase;

/**
 * A role built from just the Classes and Attendance modules (a school
 * deliberately keeping the Enrollments module off a Teacher role — see
 * EnrollmentPolicy::viewAny()'s own docblock) must still be able to open a
 * class's roster, since that's the only way to reach the Attendance action
 * from ClassStudents.vue. Regression coverage for that exact permission gap.
 *
 * Every test here grants ENROLLMENTS_CREATE alongside whatever's under test,
 * purely to seed one enrollment as the same acting user — a second call to
 * actingAsAdminWithPermissions() would spin up a whole second tenant (see its
 * own docblock), not just swap the current user's permissions.
 */
class EnrollmentViewPermissionTest extends TestCase
{
    use HasAcademicAdmin, HasAcademicCatalog, RefreshDatabase;

    private function enrollOneStudent(): void
    {
        $this->setUpAcademicCatalog();
        $student = Student::factory()->create();

        $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
        ])->assertCreated();
    }

    public function test_a_classes_and_attendance_only_role_can_list_a_classs_roster(): void
    {
        $this->actingAsAdminWithPermissions([
            Permissions::ENROLLMENTS_CREATE, Permissions::CLASSES_VIEW, Permissions::ATTENDANCE_VIEW,
        ]);
        $this->enrollOneStudent();

        $response = $this->getJson('/api/v1/enrollments?filter[class_id]='.$this->computerEveningClass->id);

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_a_classes_and_attendance_only_role_can_view_a_single_enrollment(): void
    {
        $this->actingAsAdminWithPermissions([
            Permissions::ENROLLMENTS_CREATE, Permissions::CLASSES_VIEW, Permissions::ATTENDANCE_CREATE,
        ]);
        $this->enrollOneStudent();
        $enrollmentId = $this->getJson('/api/v1/enrollments')->json('data.0.id');

        $this->getJson("/api/v1/enrollments/{$enrollmentId}")->assertOk();
    }

    public function test_attendance_update_alone_is_also_enough_to_view_the_roster(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::ATTENDANCE_UPDATE]);
        $this->enrollOneStudent();

        $this->getJson('/api/v1/enrollments')->assertOk();
    }

    public function test_a_role_with_neither_enrollments_nor_attendance_permissions_is_forbidden(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CLASSES_VIEW]);

        $this->getJson('/api/v1/enrollments')->assertForbidden();
    }
}
