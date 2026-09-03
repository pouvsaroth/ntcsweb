<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Student;
use App\Support\Audit\AuditAction;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\TestCase;

class EnrollmentAuditTest extends TestCase
{
    use HasAcademicAdmin, HasAcademicCatalog, RefreshDatabase;

    public function test_a_package_enrollment_records_an_enrollment_invoiced_audit_entry(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->forTenant($this->tenant)->create();

        $enrollmentId = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
        ])->assertCreated()->json('data.id');

        $log = AuditLog::where('action', AuditAction::ENROLLMENT_INVOICED)->firstOrFail();
        $this->assertSame(Enrollment::class, $log->auditable_type);
        $this->assertSame($enrollmentId, $log->auditable_id);
        $this->assertStringContainsString('MS Word 2024', (string) $log->description);
        $this->assertStringContainsString('24', (string) $log->description);
    }

    public function test_changing_a_packages_price_records_a_package_price_changed_audit_entry(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::COURSE_PACKAGES_UPDATE]);
        $this->setUpAcademicCatalog();

        $this->putJson("/api/v1/course-packages/{$this->msWordPackage->id}", [
            'fee_monthly' => 30,
        ])->assertOk();

        $log = AuditLog::where('action', AuditAction::PACKAGE_PRICE_CHANGED)->firstOrFail();
        $this->assertStringContainsString('24', (string) $log->description);
        $this->assertStringContainsString('30', (string) $log->description);
    }
}
