<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\ClassroomTable;
use App\Models\CoursePackage;
use App\Models\Enrollment;
use App\Models\EnrollmentTransferHistory;
use App\Models\Product;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Support\Audit\AuditAction;
use App\Support\Authorization\Permissions;
use App\Support\Billing\ProductType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\TestCase;

class EnrollmentTransferTest extends TestCase
{
    use HasAcademicAdmin, HasAcademicCatalog, RefreshDatabase;

    public function test_transferring_an_enrollment_updates_the_same_row_and_logs_the_change(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::ENROLLMENTS_TRANSFER, Permissions::ENROLLMENTS_VIEW]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->create();

        $originalId = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
        ])->assertCreated()->json('data.id');

        $newClass = SchoolClass::factory()->forProgram($this->computerProgram)->create(['name' => 'Computer Evening B']);

        $before = Enrollment::findOrFail($originalId);
        $response = $this->postJson("/api/v1/enrollments/{$originalId}/transfer", ['class_id' => $newClass->id]);

        $response->assertOk();
        $response->assertJsonPath('data.id', $originalId);
        $response->assertJsonPath('data.class.id', $newClass->id);
        $response->assertJsonPath('data.status', 'active');

        $after = Enrollment::findOrFail($originalId);
        $this->assertSame($newClass->id, $after->class_id);
        $this->assertSame($before->enrollments_code, $after->enrollments_code);
        $this->assertSame(1, Enrollment::where('student_id', $student->id)->count());

        $history = EnrollmentTransferHistory::where('enrollment_id', $originalId)->sole();
        $this->assertSame($this->computerEveningClass->id, $history->from_class_id);
        $this->assertSame($newClass->id, $history->to_class_id);

        $this->getJson("/api/v1/enrollments/{$originalId}/transfer-history")
            ->assertOk()
            ->assertJsonPath('data.0.to_class', 'Computer Evening B');

        $log = AuditLog::where('action', AuditAction::ENROLLMENT_TRANSFERRED)->firstOrFail();
        $this->assertStringContainsString('Computer Evening B', (string) $log->description);
    }

    /**
     * A class is just a schedule/room/teacher — transferring only requires
     * the destination class to be in the same program (see
     * EnrollmentCrossProgramRejectionTest for the same rule on the initial
     * enrollment path).
     */
    public function test_transferring_to_a_same_program_class_still_succeeds(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::ENROLLMENTS_TRANSFER]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->create();

        $originalId = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
        ])->assertCreated()->json('data.id');

        $unrelatedClass = SchoolClass::factory()->forProgram($this->computerProgram)->create(['name' => 'No package here']);

        $response = $this->postJson("/api/v1/enrollments/{$originalId}/transfer", ['class_id' => $unrelatedClass->id]);

        $response->assertOk();
        $this->assertSame($unrelatedClass->id, Enrollment::findOrFail($originalId)->class_id);
        $this->assertSame(1, Enrollment::count());
    }

    public function test_transferring_to_a_class_in_a_different_program_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::ENROLLMENTS_TRANSFER]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->create();

        $originalId = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
        ])->assertCreated()->json('data.id');

        $englishClass = SchoolClass::factory()->create(['name' => 'English class']);

        $response = $this->postJson("/api/v1/enrollments/{$originalId}/transfer", ['class_id' => $englishClass->id]);

        $response->assertUnprocessable();
        $this->assertSame('active', Enrollment::findOrFail($originalId)->status);
        $this->assertSame(1, Enrollment::count());
    }

    public function test_transferring_to_a_class_whose_room_has_tables_requires_a_table(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::ENROLLMENTS_TRANSFER]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->create();

        $originalId = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
        ])->assertCreated()->json('data.id');

        $room = Classroom::factory()->create();
        $table = ClassroomTable::factory()->create(['classroom_id' => $room->id]);
        $newClass = SchoolClass::factory()->forProgram($this->computerProgram)->inRoom($room)->create();

        $this->postJson("/api/v1/enrollments/{$originalId}/transfer", ['class_id' => $newClass->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('table_id');

        $response = $this->postJson("/api/v1/enrollments/{$originalId}/transfer", ['class_id' => $newClass->id, 'table_id' => $table->id]);

        $response->assertOk();
        $response->assertJsonPath('data.table.id', $table->id);
    }

    /** A second package in the same program — "the student switches from MS Word to Excel." */
    private function excelPackage(): CoursePackage
    {
        $product = Product::factory()->create(['code' => 'EXCEL2024', 'name' => 'Excel 2024', 'type' => ProductType::COURSE_FEE, 'price' => 30]);

        $package = CoursePackage::factory()->forProgram($this->computerProgram)->create([
            'code' => 'EXCEL2024', 'name' => 'Excel 2024', 'price' => 30,
            'fee_monthly' => 25, 'fee_term' => 30, 'fee_video' => 18,
            'fee_monthly_online' => 22, 'fee_term_online' => 28,
            'product_id' => $product->getKey(),
        ]);
        $package->books()->sync([$this->excelBook->getKey()]);

        return $package;
    }

    public function test_transferring_to_a_different_course_recomputes_the_fee_while_unpaid(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::ENROLLMENTS_TRANSFER]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->create();
        $excel = $this->excelPackage();

        $originalId = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
        ])->assertCreated()->json('data.id');

        $newClass = SchoolClass::factory()->forProgram($this->computerProgram)->create(['name' => 'Computer Evening B']);

        $response = $this->postJson("/api/v1/enrollments/{$originalId}/transfer", [
            'class_id' => $newClass->id,
            'course_package_id' => $excel->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.course_package.id', $excel->id);
        $response->assertJsonPath('data.class.id', $newClass->id);
        $this->assertSame($excel->id, Enrollment::findOrFail($originalId)->course_package_id);
        $this->assertSame($excel->id, EnrollmentTransferHistory::where('enrollment_id', $originalId)->sole()->to_course_package_id);
    }

    public function test_transferring_to_a_different_course_is_rejected_once_the_enrollment_is_paid(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::ENROLLMENTS_TRANSFER]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->create();
        $excel = $this->excelPackage();

        $originalId = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
            'received_amount' => 24,
            'payment_method' => 'CASH',
        ])->assertCreated()->json('data.id');

        $newClass = SchoolClass::factory()->forProgram($this->computerProgram)->create(['name' => 'Computer Evening B']);

        $response = $this->postJson("/api/v1/enrollments/{$originalId}/transfer", [
            'class_id' => $newClass->id,
            'course_package_id' => $excel->id,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('course_package_id');
        $this->assertSame('active', Enrollment::findOrFail($originalId)->status);
    }

    /**
     * Students moved by the old transferClass() have their payment on a
     * dropped enrollment and a fresh active one for the same course with no
     * invoice of its own — that payment must still lock the course.
     */
    public function test_transferring_to_a_different_course_is_rejected_when_paid_on_a_dropped_enrollment_for_the_same_course(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::ENROLLMENTS_TRANSFER, Permissions::ENROLLMENTS_VIEW]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->create();
        $excel = $this->excelPackage();

        $droppedId = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
            'received_amount' => 10,
            'payment_method' => 'CASH',
        ])->assertCreated()->json('data.id');

        $dropped = Enrollment::findOrFail($droppedId);
        $dropped->update(['status' => Enrollment::STATUS_DROPPED]);

        $active = Enrollment::factory()->create([
            'student_id' => $student->id,
            'class_id' => $dropped->class_id,
            'course_package_id' => $dropped->course_package_id,
            'academic_program_id' => $dropped->academic_program_id,
            'enrollments_code' => $dropped->enrollments_code.'-B',
        ]);

        $this->getJson("/api/v1/enrollments/{$active->id}")->assertOk()->assertJsonPath('data.is_paid', true);

        $this->postJson("/api/v1/enrollments/{$active->id}/transfer", [
            'class_id' => $active->class_id,
            'course_package_id' => $excel->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('course_package_id');

        $this->assertSame($this->msWordPackage->id, $active->fresh()->course_package_id);
    }

    /**
     * A transfer moves a student to a different class/table/course — it is
     * not a new enrollment, so the new row must keep the student's original
     * enrolled_at rather than stamping the transfer date. Regression test
     * for a bug where transferClass() always wrote now() here.
     */
    public function test_transferring_an_enrollment_keeps_the_original_enrolled_at_date(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::ENROLLMENTS_TRANSFER]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->create();

        $originalId = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
            'enrolled_at' => '2025-01-15',
        ])->assertCreated()->json('data.id');

        $newClass = SchoolClass::factory()->forProgram($this->computerProgram)->create(['name' => 'Computer Evening B']);

        $response = $this->postJson("/api/v1/enrollments/{$originalId}/transfer", ['class_id' => $newClass->id]);

        $response->assertOk();
        $response->assertJsonPath('data.enrolled_at', '2025-01-15');
    }

    public function test_transferring_to_a_different_class_of_the_same_course_still_succeeds_once_paid(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::ENROLLMENTS_TRANSFER]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->create();

        $originalId = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
            'received_amount' => 24,
            'payment_method' => 'CASH',
        ])->assertCreated()->json('data.id');

        $newClass = SchoolClass::factory()->forProgram($this->computerProgram)->create(['name' => 'Computer Evening B']);

        $response = $this->postJson("/api/v1/enrollments/{$originalId}/transfer", ['class_id' => $newClass->id]);

        $response->assertOk();
        $response->assertJsonPath('data.class.id', $newClass->id);
        $response->assertJsonPath('data.course_package.id', $this->msWordPackage->id);
    }

    /**
     * Opening "Change Class" and only switching tables (or keeping the same
     * table) used to fail validation, since the student's own seat counted
     * as taken.
     */
    public function test_keeping_the_same_class_and_table_does_not_count_as_taken(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::ENROLLMENTS_TRANSFER]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->create();

        $room = Classroom::factory()->create();
        $tableA = ClassroomTable::factory()->create(['classroom_id' => $room->id]);
        $tableB = ClassroomTable::factory()->create(['classroom_id' => $room->id]);
        $class = SchoolClass::factory()->forProgram($this->computerProgram)->inRoom($room)->create();

        $enrollmentId = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $class->id,
            'table_id' => $tableA->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/enrollments/{$enrollmentId}/transfer", ['class_id' => $class->id, 'table_id' => $tableA->id])->assertOk();
        $this->assertSame(0, EnrollmentTransferHistory::count());

        $this->postJson("/api/v1/enrollments/{$enrollmentId}/transfer", ['class_id' => $class->id, 'table_id' => $tableB->id])
            ->assertOk()
            ->assertJsonPath('data.id', $enrollmentId)
            ->assertJsonPath('data.table.id', $tableB->id);

        $history = EnrollmentTransferHistory::sole();
        $this->assertSame($tableA->id, $history->from_table_id);
        $this->assertSame($tableB->id, $history->to_table_id);
        $this->assertSame(1, Enrollment::count());
    }
}
