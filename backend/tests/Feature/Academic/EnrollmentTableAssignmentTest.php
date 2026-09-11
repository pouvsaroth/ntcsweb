<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Classroom;
use App\Models\ClassroomTable;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\TestCase;

/**
 * Classroom-table assignment/locking behavior on package-based enrollment —
 * ported from the legacy book-enrollment endpoint's coverage once that path
 * was removed (see EnrollmentPackageServiceTest for the table-required case
 * itself).
 */
class EnrollmentTableAssignmentTest extends TestCase
{
    use HasAcademicAdmin, HasAcademicCatalog, RefreshDatabase;

    /** @return array{0: SchoolClass, 1: ClassroomTable} */
    private function classWithTable(): array
    {
        $room = Classroom::factory()->create();
        $table = ClassroomTable::factory()->create(['classroom_id' => $room->id]);
        $class = SchoolClass::factory()->forProgram($this->computerProgram)->inRoom($room)->create();

        return [$class, $table];
    }

    public function test_a_class_whose_room_has_no_tables_configured_does_not_require_a_table(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->create();
        $room = Classroom::factory()->create();
        $class = SchoolClass::factory()->forProgram($this->computerProgram)->inRoom($room)->create();

        $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $class->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
        ])->assertCreated();
    }

    public function test_two_students_cannot_share_the_same_table_in_one_class(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        [$class, $table] = $this->classWithTable();
        $studentA = Student::factory()->create();
        $studentB = Student::factory()->create();

        $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $studentA->id, 'class_id' => $class->id, 'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term', 'table_id' => $table->id,
        ])->assertCreated();

        $response = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $studentB->id, 'class_id' => $class->id, 'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term', 'table_id' => $table->id,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('table_id');
    }

    public function test_dropping_an_enrollment_frees_its_table_for_reuse(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::ENROLLMENTS_UPDATE]);
        $this->setUpAcademicCatalog();
        [$class, $table] = $this->classWithTable();
        $studentA = Student::factory()->create();
        $studentB = Student::factory()->create();

        $first = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $studentA->id, 'class_id' => $class->id, 'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term', 'table_id' => $table->id,
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/enrollments/{$first}", ['status' => Enrollment::STATUS_DROPPED])->assertOk();

        $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $studentB->id, 'class_id' => $class->id, 'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term', 'table_id' => $table->id,
        ])->assertCreated();
    }

    public function test_a_table_from_a_different_classroom_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        [$class] = $this->classWithTable();
        $student = Student::factory()->create();
        $otherRoomTable = ClassroomTable::factory()->create();

        $response = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id, 'class_id' => $class->id, 'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term', 'table_id' => $otherRoomTable->id,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('table_id');
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
