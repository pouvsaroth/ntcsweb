<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\TestCase;

class MonthlyInvoiceTest extends TestCase
{
    use HasAcademicAdmin, HasAcademicCatalog, RefreshDatabase;

    public function test_a_monthly_enrollment_appears_with_a_computed_next_payment_date(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::INVOICES_VIEW]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->create();

        $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'enrolled_at' => '2026-08-18',
            'fee_type' => 'monthly',
        ])->assertCreated();

        $response = $this->getJson('/api/v1/monthly-invoices');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.student.id', $student->id);
        $response->assertJsonPath('data.0.course', 'MS Word 2024');
        $response->assertJsonPath('data.0.start_date', '2026-08-18');
        $response->assertJsonPath('data.0.monthly_invoices_count', 1);
        // One invoice issued so far (month 1) -> next payment one month after start.
        $response->assertJsonPath('data.0.next_payment_date', '2026-09-18');

        $enrollment = Enrollment::firstOrFail();
        $invoice = Invoice::firstOrFail();
        $response->assertJsonPath('data.0.latest_invoice_id', $invoice->id);
        $response->assertJsonPath('data.0.latest_invoice_number', $invoice->invoice_number);
        $this->assertSame($enrollment->student_id, $student->id);
    }

    public function test_reprinting_downloads_the_most_recently_issued_monthly_invoice_not_the_first_one(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::INVOICES_VIEW]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->create();

        $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'enrolled_at' => '2026-08-18',
            'fee_type' => 'monthly',
        ])->assertCreated();

        $enrollment = Enrollment::firstOrFail();
        $firstInvoice = Invoice::firstOrFail();

        // A second month's invoice, recorded later — same enrollment, same shape a real monthly billing run would produce.
        $secondInvoice = Invoice::factory()->create(['student_id' => $student->id, 'payment_type' => 'monthly']);
        InvoiceItem::factory()->create([
            'invoice_id' => $secondInvoice->id,
            'reference_type' => Enrollment::class,
            'reference_id' => $enrollment->id,
        ]);

        $response = $this->getJson('/api/v1/monthly-invoices');

        $response->assertOk();
        $response->assertJsonPath('data.0.monthly_invoices_count', 2);
        $response->assertJsonPath('data.0.latest_invoice_id', $secondInvoice->id);
        $response->assertJsonPath('data.0.latest_invoice_number', $secondInvoice->invoice_number);

        // The id the tab hands the reprint button to is a real, downloadable invoice.
        $this->get("/api/v1/invoices/{$secondInvoice->id}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertNotSame($firstInvoice->id, $secondInvoice->id);
    }

    public function test_a_term_enrollment_is_excluded_from_the_monthly_list(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::INVOICES_VIEW]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->create();

        $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
        ])->assertCreated();

        $response = $this->getJson('/api/v1/monthly-invoices');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_viewing_the_monthly_invoice_list_requires_the_invoices_view_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/v1/monthly-invoices')->assertForbidden();
    }
}
