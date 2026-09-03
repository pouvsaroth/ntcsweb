<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\CoursePackage;
use App\Models\Enrollment;
use App\Models\InvoiceItem;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\TestCase;

/**
 * The spec's own critical test: "Never recalculate historical invoices using
 * today's price." A package's price is a live catalog value; an already
 *-issued InvoiceItem must keep the price it was billed at forever.
 */
class CoursePackagePriceChangeTest extends TestCase
{
    use HasAcademicAdmin, HasAcademicCatalog, RefreshDatabase;

    public function test_changing_a_packages_price_does_not_alter_an_already_issued_invoice_item(): void
    {
        $this->actingAsAdminWithPermissions([
            Permissions::ENROLLMENTS_CREATE, Permissions::COURSE_PACKAGES_UPDATE,
        ]);
        $this->setUpAcademicCatalog();

        $student = Student::factory()->forTenant($this->tenant)->create();

        $response = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'enrolled_at' => '2026-01-15',
        ])->assertCreated();

        $enrollment = Enrollment::findOrFail($response->json('data.id'));
        $this->assertSame('24.00', (string) $enrollment->fee);

        $item = InvoiceItem::where('reference_type', Enrollment::class)->where('reference_id', $enrollment->id)->firstOrFail();
        $this->assertSame('24.00', (string) $item->unit_price);

        // Admin raises the package's price after this student already enrolled.
        $this->putJson("/api/v1/course-packages/{$this->msWordPackage->id}", [
            'code' => $this->msWordPackage->code,
            'name' => $this->msWordPackage->name,
            'academic_program_id' => $this->msWordPackage->academic_program_id,
            'currency' => 'USD',
            'fee_monthly' => 30,
        ])->assertOk();

        $this->assertSame('30.00', (string) $this->msWordPackage->fresh()->price);
        $this->assertSame('30.00', (string) $this->msWordPackage->fresh()->product->price);

        // The old invoice item must be untouched.
        $this->assertSame('24.00', (string) $item->fresh()->unit_price);
        $this->assertSame('24.00', (string) $enrollment->fresh()->fee);
    }

    public function test_a_new_enrollment_after_a_price_change_is_billed_at_the_new_price(): void
    {
        $this->actingAsAdminWithPermissions([
            Permissions::ENROLLMENTS_CREATE, Permissions::COURSE_PACKAGES_UPDATE,
        ]);
        $this->setUpAcademicCatalog();

        CoursePackage::query()->whereKey($this->msWordPackage->id)->update(['price' => 30]);
        $this->msWordPackage->product->update(['price' => 30]);

        $student = Student::factory()->forTenant($this->tenant)->create();
        $response = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'enrolled_at' => '2026-02-01',
        ])->assertCreated();

        $this->assertSame(30, (int) $response->json('data.fee'));
    }
}
