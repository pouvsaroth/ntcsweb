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

class EnrollmentCancelTest extends TestCase
{
    use HasAcademicAdmin, HasAcademicCatalog, RefreshDatabase;

    private function enroll(Student $student): int
    {
        return $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
        ])->assertCreated()->json('data.id');
    }

    public function test_cancelling_an_enrollment_marks_it_dropped_and_audits_the_reason(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::ENROLLMENTS_CANCEL]);
        $this->setUpAcademicCatalog();
        $enrollmentId = $this->enroll(Student::factory()->forTenant($this->tenant)->create());

        $response = $this->postJson("/api/v1/enrollments/{$enrollmentId}/cancel", ['reason' => 'Moved to another school']);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'dropped');

        $log = AuditLog::where('action', AuditAction::ENROLLMENT_CANCELLED)->firstOrFail();
        $this->assertStringContainsString('Moved to another school', (string) $log->description);
    }

    public function test_an_already_dropped_enrollment_cannot_be_cancelled_again(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::ENROLLMENTS_CANCEL]);
        $this->setUpAcademicCatalog();
        $enrollmentId = $this->enroll(Student::factory()->forTenant($this->tenant)->create());
        $this->postJson("/api/v1/enrollments/{$enrollmentId}/cancel", ['reason' => 'first'])->assertOk();

        $response = $this->postJson("/api/v1/enrollments/{$enrollmentId}/cancel", ['reason' => 'second']);

        $response->assertUnprocessable();
        $this->assertSame('dropped', Enrollment::findOrFail($enrollmentId)->status);
    }

    public function test_cancel_requires_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        $enrollmentId = $this->enroll(Student::factory()->forTenant($this->tenant)->create());

        $this->postJson("/api/v1/enrollments/{$enrollmentId}/cancel", ['reason' => 'x'])->assertForbidden();
    }
}
