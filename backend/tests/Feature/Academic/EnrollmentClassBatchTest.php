<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Classroom;
use App\Models\ClassroomTable;
use App\Models\Company;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\TestCase;

/**
 * Enrollments > Add students to class: many students into a company class
 * (Billing > Companies) in one go, with no invoices — the company pays one
 * total for the class. See EnrollmentService::enrollClassBatch().
 */
class EnrollmentClassBatchTest extends TestCase
{
    use HasAcademicAdmin, HasAcademicCatalog, RefreshDatabase;

    private function companyClass(array $attributes = []): SchoolClass
    {
        return SchoolClass::factory()->forProgram($this->computerProgram)->create(['company_id' => Company::factory()->create()->id, ...$attributes]);
    }

    public function test_many_students_are_enrolled_into_a_company_class_with_no_invoices(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        $class = $this->companyClass();
        $students = Student::factory()->count(3)->create();

        $response = $this->postJson('/api/v1/enrollments/class-batch', [
            'class_id' => $class->id,
            'course_package_id' => $this->msWordPackage->id,
            'enrolled_at' => '2026-11-04',
            'students' => $students->map(fn ($s) => ['student_id' => $s->id])->all(),
        ]);

        $response->assertCreated()->assertJsonCount(3, 'data');
        $this->assertSame(3, Enrollment::where('class_id', $class->id)->where('course_package_id', $this->msWordPackage->id)->where('status', Enrollment::STATUS_ACTIVE)->count());
        $this->assertSame(0, Invoice::count());
    }

    public function test_a_class_not_paid_by_a_company_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->create();

        $this->postJson('/api/v1/enrollments/class-batch', [
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'students' => [['student_id' => $student->id]],
        ])->assertUnprocessable()->assertJsonValidationErrors('class_id');

        $this->assertSame(0, Enrollment::count());
    }

    public function test_one_failing_student_saves_nobody_and_is_reported_on_its_own_row(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        $class = $this->companyClass();
        [$fresh, $alreadyIn] = Student::factory()->count(2)->create()->all();

        $this->postJson('/api/v1/enrollments/class-batch', [
            'class_id' => $class->id,
            'course_package_id' => $this->msWordPackage->id,
            'students' => [['student_id' => $alreadyIn->id]],
        ])->assertCreated();

        $this->postJson('/api/v1/enrollments/class-batch', [
            'class_id' => $class->id,
            'course_package_id' => $this->msWordPackage->id,
            'students' => [['student_id' => $fresh->id], ['student_id' => $alreadyIn->id]],
        ])->assertUnprocessable()->assertJsonValidationErrors('students.1.student_id')->assertJsonMissingValidationErrors('students.0.student_id');

        $this->assertSame(0, Enrollment::where('student_id', $fresh->id)->count());
    }

    public function test_the_same_student_twice_in_the_list_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        $class = $this->companyClass();
        $student = Student::factory()->create();

        $this->postJson('/api/v1/enrollments/class-batch', [
            'class_id' => $class->id,
            'course_package_id' => $this->msWordPackage->id,
            'students' => [['student_id' => $student->id], ['student_id' => $student->id]],
        ])->assertUnprocessable()->assertJsonValidationErrors('students.1.student_id');
    }

    public function test_tables_are_required_and_one_table_cannot_go_to_two_students(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        $room = Classroom::factory()->create();
        $table = ClassroomTable::factory()->create(['classroom_id' => $room->id]);
        $shared = ClassroomTable::factory()->create(['classroom_id' => $room->id, 'allow_many_students' => true]);
        $class = $this->companyClass(['classroom_id' => $room->id]);
        [$a, $b, $c] = Student::factory()->count(3)->create()->all();

        $this->postJson('/api/v1/enrollments/class-batch', [
            'class_id' => $class->id,
            'course_package_id' => $this->msWordPackage->id,
            'students' => [['student_id' => $a->id, 'table_id' => $table->id], ['student_id' => $b->id, 'table_id' => $table->id], ['student_id' => $c->id]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['students.1.table_id', 'students.2.table_id'])->assertJsonMissingValidationErrors('students.0.table_id');

        $this->postJson('/api/v1/enrollments/class-batch', [
            'class_id' => $class->id,
            'course_package_id' => $this->msWordPackage->id,
            'students' => [['student_id' => $a->id, 'table_id' => $table->id], ['student_id' => $b->id, 'table_id' => $shared->id], ['student_id' => $c->id, 'table_id' => $shared->id]],
        ])->assertCreated();

        $this->assertSame(3, Enrollment::where('class_id', $class->id)->count());
    }

    public function test_the_one_student_form_refuses_a_company_class(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        $class = $this->companyClass();
        $student = Student::factory()->create();

        $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $class->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
        ])->assertUnprocessable()->assertJsonValidationErrors('class_id');

        $this->assertSame(0, Invoice::count());
    }

    public function test_a_student_cannot_move_between_a_company_class_and_a_self_paid_class(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::ENROLLMENTS_TRANSFER]);
        $this->setUpAcademicCatalog();
        $class = $this->companyClass();
        $student = Student::factory()->create();

        $selfPaidId = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/enrollments/{$selfPaidId}/transfer", ['class_id' => $class->id])
            ->assertUnprocessable()->assertJsonValidationErrors('class_id');

        // Between two classes of the same company is fine.
        $other = Student::factory()->create();
        $sameCompanyClass = SchoolClass::factory()->forProgram($this->computerProgram)->create(['company_id' => $class->company_id]);
        $companyEnrollmentId = $this->postJson('/api/v1/enrollments/class-batch', [
            'class_id' => $class->id,
            'course_package_id' => $this->msWordPackage->id,
            'students' => [['student_id' => $other->id]],
        ])->assertCreated()->json('data.0.id');

        $this->postJson("/api/v1/enrollments/{$companyEnrollmentId}/transfer", ['class_id' => $sameCompanyClass->id])->assertOk();
    }

    public function test_it_needs_the_enrollment_create_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_VIEW]);
        $this->setUpAcademicCatalog();
        $class = $this->companyClass();

        $this->postJson('/api/v1/enrollments/class-batch', [
            'class_id' => $class->id,
            'course_package_id' => $this->msWordPackage->id,
            'students' => [['student_id' => Student::factory()->create()->id]],
        ])->assertForbidden();
    }
}
