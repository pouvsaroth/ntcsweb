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
 * ENROLLMENTS_CHANGE_TABLE is deliberately narrower than ENROLLMENTS_TRANSFER
 * — a Teacher role can reseat their own students without being able to move
 * them to a different class or course. See EnrollmentPolicy::changeTable()
 * and Permissions::ENROLLMENTS_CHANGE_TABLE.
 */
class EnrollmentChangeTableTest extends TestCase
{
    use HasAcademicAdmin, HasAcademicCatalog, RefreshDatabase;

    /** @return array{0: SchoolClass, 1: ClassroomTable, 2: ClassroomTable} */
    private function classWithTwoTables(): array
    {
        $room = Classroom::factory()->create();
        $tableA = ClassroomTable::factory()->create(['classroom_id' => $room->id]);
        $tableB = ClassroomTable::factory()->create(['classroom_id' => $room->id]);
        $class = SchoolClass::factory()->forProgram($this->computerProgram)->inRoom($room)->create();

        return [$class, $tableA, $tableB];
    }

    public function test_a_change_table_only_role_can_reseat_a_student_in_place(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        [$class, $tableA, $tableB] = $this->classWithTwoTables();
        $student = Student::factory()->create();

        $enrollmentId = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id, 'class_id' => $class->id, 'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term', 'table_id' => $tableA->id,
        ])->assertCreated()->json('data.id');

        $before = Enrollment::findOrFail($enrollmentId);

        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CHANGE_TABLE]);

        $response = $this->patchJson("/api/v1/enrollments/{$enrollmentId}/table", ['table_id' => $tableB->id]);

        $response->assertOk();
        $response->assertJsonPath('data.table.id', $tableB->id);

        // Same row, same identity — an in-place update, not a drop+recreate.
        $after = Enrollment::findOrFail($enrollmentId);
        $this->assertSame($before->id, $after->id);
        $this->assertSame($before->enrollments_code, $after->enrollments_code);
        $this->assertSame($before->enrolled_at->toDateString(), $after->enrolled_at->toDateString());
        $this->assertSame($before->class_id, $after->class_id);
        $this->assertSame($before->course_package_id, $after->course_package_id);
        $this->assertSame($tableB->id, $after->table_id);
    }

    public function test_it_has_no_field_to_change_class_or_course(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        [$class, $tableA, $tableB] = $this->classWithTwoTables();
        $student = Student::factory()->create();
        $otherClass = SchoolClass::factory()->forProgram($this->computerProgram)->create();

        $enrollmentId = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id, 'class_id' => $class->id, 'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term', 'table_id' => $tableA->id,
        ])->assertCreated()->json('data.id');

        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CHANGE_TABLE]);

        // A class_id/course_package_id in the payload is simply not a
        // recognised field here — FormRequest silently drops it.
        $this->patchJson("/api/v1/enrollments/{$enrollmentId}/table", [
            'table_id' => $tableB->id,
            'class_id' => $otherClass->id,
            'course_package_id' => $this->msWordPackage->id + 1,
        ])->assertOk();

        $enrollment = Enrollment::findOrFail($enrollmentId);
        $this->assertSame($class->id, $enrollment->class_id);
        $this->assertSame($tableB->id, $enrollment->table_id);
    }

    public function test_a_table_from_a_different_classroom_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        [$class, $tableA] = $this->classWithTwoTables();
        $student = Student::factory()->create();
        $otherRoomTable = ClassroomTable::factory()->create();

        $enrollmentId = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id, 'class_id' => $class->id, 'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term', 'table_id' => $tableA->id,
        ])->assertCreated()->json('data.id');

        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CHANGE_TABLE]);

        $response = $this->patchJson("/api/v1/enrollments/{$enrollmentId}/table", ['table_id' => $otherRoomTable->id]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('table_id');
    }

    public function test_a_table_already_taken_in_the_class_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        [$class, $tableA, $tableB] = $this->classWithTwoTables();
        $studentA = Student::factory()->create();
        $studentB = Student::factory()->create();

        $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $studentA->id, 'class_id' => $class->id, 'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term', 'table_id' => $tableA->id,
        ])->assertCreated();

        $enrollmentB = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $studentB->id, 'class_id' => $class->id, 'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term', 'table_id' => $tableB->id,
        ])->assertCreated()->json('data.id');

        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CHANGE_TABLE]);

        $response = $this->patchJson("/api/v1/enrollments/{$enrollmentB}/table", ['table_id' => $tableA->id]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('table_id');
    }

    public function test_a_role_with_only_full_transfer_cannot_use_the_narrow_table_endpoint(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        [$class, $tableA, $tableB] = $this->classWithTwoTables();
        $student = Student::factory()->create();

        $enrollmentId = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id, 'class_id' => $class->id, 'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term', 'table_id' => $tableA->id,
        ])->assertCreated()->json('data.id');

        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_TRANSFER]);

        $this->patchJson("/api/v1/enrollments/{$enrollmentId}/table", ['table_id' => $tableB->id])->assertForbidden();
    }

    public function test_changing_a_table_requires_the_change_table_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        [$class, $tableA] = $this->classWithTwoTables();
        $student = Student::factory()->create();

        $enrollmentId = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id, 'class_id' => $class->id, 'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term', 'table_id' => $tableA->id,
        ])->assertCreated()->json('data.id');

        $this->actingAsAdminWithPermissions([]);

        $this->patchJson("/api/v1/enrollments/{$enrollmentId}/table", ['table_id' => $tableA->id])->assertForbidden();
    }

    public function test_a_change_table_only_role_can_still_fetch_the_available_tables_list(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CHANGE_TABLE]);
        $this->setUpAcademicCatalog();
        [$class] = $this->classWithTwoTables();

        $this->getJson("/api/v1/classes/{$class->id}/available-tables")->assertOk();
    }
}
