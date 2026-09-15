<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

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
