<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Enrollment;
use App\Models\EnrollmentStatusHistory;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\TestCase;

class EnrollmentStatusTest extends TestCase
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

    public function test_a_routine_status_change_needs_no_reason_or_date(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::ENROLLMENTS_CHANGE_STATUS]);
        $this->setUpAcademicCatalog();
        $enrollmentId = $this->enroll(Student::factory()->forTenant($this->tenant)->create());

        $response = $this->postJson("/api/v1/enrollments/{$enrollmentId}/status", ['status' => Enrollment::STATUS_EXAM_READY]);

        $response->assertOk();
        $response->assertJsonPath('data.status', Enrollment::STATUS_EXAM_READY);
        $response->assertJsonPath('data.status_reason', null);
    }

    public function test_abandoned_requires_a_reason_and_an_effective_date(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::ENROLLMENTS_CHANGE_STATUS]);
        $this->setUpAcademicCatalog();
        $enrollmentId = $this->enroll(Student::factory()->forTenant($this->tenant)->create());

        $this->postJson("/api/v1/enrollments/{$enrollmentId}/status", ['status' => Enrollment::STATUS_ABANDONED])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason', 'effective_date']);

        $response = $this->postJson("/api/v1/enrollments/{$enrollmentId}/status", [
            'status' => Enrollment::STATUS_ABANDONED,
            'reason' => 'Moved abroad',
            'effective_date' => '2026-02-01',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', Enrollment::STATUS_ABANDONED);
        $response->assertJsonPath('data.status_reason', 'Moved abroad');
        $response->assertJsonPath('data.status_effective_date', '2026-02-01');
    }

    public function test_a_status_change_is_recorded_in_history(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::ENROLLMENTS_CHANGE_STATUS, Permissions::ENROLLMENTS_VIEW]);
        $this->setUpAcademicCatalog();
        $enrollmentId = $this->enroll(Student::factory()->forTenant($this->tenant)->create());

        $this->postJson("/api/v1/enrollments/{$enrollmentId}/status", [
            'status' => Enrollment::STATUS_SUSPENDED,
            'reason' => 'Medical leave',
            'effective_date' => '2026-03-01',
        ])->assertOk();

        $history = EnrollmentStatusHistory::where('enrollment_id', $enrollmentId)->firstOrFail();
        $this->assertSame(Enrollment::STATUS_ACTIVE, $history->from_status);
        $this->assertSame(Enrollment::STATUS_SUSPENDED, $history->to_status);
        $this->assertSame('Medical leave', $history->reason);
        $this->assertSame($admin->id, $history->changed_by);

        $response = $this->getJson("/api/v1/enrollments/{$enrollmentId}/status-history");
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.to_status', Enrollment::STATUS_SUSPENDED);
    }

    public function test_changing_status_requires_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        $enrollmentId = $this->enroll(Student::factory()->forTenant($this->tenant)->create());

        $this->postJson("/api/v1/enrollments/{$enrollmentId}/status", ['status' => Enrollment::STATUS_COMPLETED])
            ->assertForbidden();
    }

    public function test_a_dropped_enrollment_cannot_have_its_status_changed(): void
    {
        $this->actingAsAdminWithPermissions([
            Permissions::ENROLLMENTS_CREATE, Permissions::ENROLLMENTS_CHANGE_STATUS, Permissions::ENROLLMENTS_CANCEL,
        ]);
        $this->setUpAcademicCatalog();
        $enrollmentId = $this->enroll(Student::factory()->forTenant($this->tenant)->create());
        $this->postJson("/api/v1/enrollments/{$enrollmentId}/cancel", ['reason' => 'x'])->assertOk();

        $this->postJson("/api/v1/enrollments/{$enrollmentId}/status", ['status' => Enrollment::STATUS_COMPLETED])
            ->assertUnprocessable();
    }
}
