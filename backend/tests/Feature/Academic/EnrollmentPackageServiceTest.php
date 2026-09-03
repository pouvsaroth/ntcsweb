<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Classroom;
use App\Models\ClassroomTable;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use App\Support\Billing\InvoiceStatus;
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
            'fee_type' => 'term',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.student.id', $student->id);
        $response->assertJsonPath('data.class.id', $this->computerEveningClass->id);
        $response->assertJsonPath('data.course_package_id', $this->msWordPackage->id);
        $response->assertJsonPath('data.academic_program_id', $this->computerProgram->id);
        $response->assertJsonPath('data.fee', 24);
        $response->assertJsonPath('data.fee_type', 'term');

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
            'fee_type' => 'term',
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
            'fee_type' => 'term',
        ])->assertCreated();

        $response = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
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
            'fee_type' => 'term',
        ])->assertUnprocessable()->assertJsonValidationErrors('table_id');

        $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $class->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
            'table_id' => $table->id,
        ])->assertCreated();
    }

    public function test_the_selected_fee_type_determines_the_billed_amount(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->forTenant($this->tenant)->create();

        $response = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'monthly',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.fee', 20);
        $response->assertJsonPath('data.fee_type', 'monthly');
        $this->assertSame('20.00', (string) Invoice::firstOrFail()->total);
    }

    public function test_a_fee_type_the_package_does_not_offer_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        $this->msWordPackage->update(['fee_video' => null]);
        $student = Student::factory()->forTenant($this->tenant)->create();

        $response = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'video',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('fee_type');
        $this->assertSame(0, Enrollment::count());
    }

    public function test_a_discount_reduces_the_invoice_total_and_is_capped_at_the_fee(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->forTenant($this->tenant)->create();

        $response = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
            'discount_price' => 4,
            'discount_reason' => 'SIBLING',
        ]);

        $response->assertCreated();
        $invoice = Invoice::firstOrFail();
        $this->assertSame('4.00', (string) $invoice->discount);
        $this->assertSame('SIBLING', $invoice->discount_reason);
        $this->assertSame('20.00', (string) $invoice->total);

        $rejected = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => Student::factory()->forTenant($this->tenant)->create()->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
            'discount_price' => 100,
        ]);
        $rejected->assertUnprocessable();
        $rejected->assertJsonValidationErrors('discount_price');
    }

    public function test_received_amount_records_a_partial_payment_and_leaves_a_debt(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->forTenant($this->tenant)->create();

        $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
            'received_amount' => 10,
            'payment_method' => 'CASH',
        ])->assertCreated();

        $invoice = Invoice::firstOrFail();
        $this->assertSame(1, Payment::count());
        $this->assertSame('10.00', (string) $invoice->paid_amount);
        $this->assertSame('14.00', (string) $invoice->balance);
        $this->assertSame(InvoiceStatus::PARTIALLY_PAID, $invoice->status);
    }

    public function test_a_received_amount_exceeding_the_fee_never_overpays_the_invoice(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->forTenant($this->tenant)->create();

        $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
            'received_amount' => 100,
            'payment_method' => 'CASH',
        ])->assertCreated();

        $invoice = Invoice::firstOrFail();
        $this->assertSame('24.00', (string) $invoice->paid_amount);
        $this->assertSame('0.00', (string) $invoice->balance);
        $this->assertSame(InvoiceStatus::PAID, $invoice->status);
        $this->assertSame('24.00', (string) Payment::firstOrFail()->amount);
    }

    public function test_no_payment_is_created_when_nothing_is_received(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->forTenant($this->tenant)->create();

        $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
        ])->assertCreated();

        $this->assertSame(0, Payment::count());
        $this->assertSame(InvoiceStatus::ISSUED, Invoice::firstOrFail()->status);
    }

    public function test_a_received_amount_without_a_payment_method_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->forTenant($this->tenant)->create();

        $response = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
            'received_amount' => 10,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('payment_method');
        $this->assertSame(0, Enrollment::count());
    }
}
