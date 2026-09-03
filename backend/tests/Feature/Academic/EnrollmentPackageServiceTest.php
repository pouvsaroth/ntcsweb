<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Classroom;
use App\Models\ClassroomTable;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\TestCase;

/**
 * The core orchestration point: Student + Class + Course Package ->
 * Enrollment + Invoice + InvoiceItem, all in one transaction, using the
 * existing InvoiceService unmodified.
 */
class EnrollmentPackageServiceTest extends TestCase
{
    use HasAcademicAdmin, HasAcademicCatalog, RefreshDatabase;

    public function test_enrolling_in_a_package_creates_one_enrollment_and_one_invoice_with_a_traceable_item(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->forTenant($this->tenant)->create();

        $response = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'enrolled_at' => '2026-01-15',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.student.id', $student->id);
        $response->assertJsonPath('data.class.id', $this->computerEveningClass->id);
        $response->assertJsonPath('data.course_package_id', $this->msWordPackage->id);
        $response->assertJsonPath('data.academic_program_id', $this->computerProgram->id);
        $response->assertJsonPath('data.fee', 24);

        $this->assertSame(1, Enrollment::count());
        $this->assertSame(1, Invoice::count());

        $enrollment = Enrollment::firstOrFail();
        $invoice = Invoice::firstOrFail();

        $this->assertSame($student->id, $invoice->student_id);
        $this->assertSame('24.00', (string) $invoice->total);
        $this->assertSame('0.00', (string) $invoice->paid_amount);
        $this->assertSame('24.00', (string) $invoice->balance);

        $item = InvoiceItem::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(Enrollment::class, $item->reference_type);
        $this->assertSame($enrollment->id, $item->reference_id);
        $this->assertSame($this->msWordPackage->product_id, $item->product_id);
        $this->assertSame('24.00', (string) $item->unit_price);
    }

    public function test_it_rejects_enrollment_into_a_package_that_is_not_active(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        $this->msWordPackage->update(['is_active' => false]);
        $student = Student::factory()->forTenant($this->tenant)->create();

        $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
        ])->assertUnprocessable();

        $this->assertSame(0, Enrollment::count());
        $this->assertSame(0, Invoice::count());
    }

    public function test_it_rejects_duplicate_active_enrollment_of_the_same_student_class_package(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->forTenant($this->tenant)->create();

        $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
        ])->assertCreated();

        $response = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('course_package_id');
        $this->assertSame(1, Enrollment::count());
    }

    public function test_a_table_is_required_when_the_classs_room_has_tables(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->forTenant($this->tenant)->create();

        $room = Classroom::factory()->forTenant($this->tenant)->create();
        $table = ClassroomTable::factory()->forTenant($this->tenant)->create(['classroom_id' => $room->id]);
        $class = SchoolClass::factory()->forTenant($this->tenant)->forProgram($this->computerProgram)->inRoom($room)->create();
        $class->coursePackages()->sync([$this->msWordPackage->id]);

        $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $class->id,
            'course_package_id' => $this->msWordPackage->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('table_id');

        $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $class->id,
            'course_package_id' => $this->msWordPackage->id,
            'table_id' => $table->id,
        ])->assertCreated();
    }
}
