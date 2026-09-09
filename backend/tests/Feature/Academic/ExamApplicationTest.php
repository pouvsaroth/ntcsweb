<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Enrollment;
use App\Models\ExamApplication;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class ExamApplicationTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private function studentWithUser(): array
    {
        $user = User::factory()->forTenant($this->tenant)->create();
        $student = Student::factory()->create(['user_id' => $user->id]);

        return [$student, $user];
    }

    private function activeEnrollment(Student $student): Enrollment
    {
        $class = SchoolClass::factory()->forTenant($this->tenant)->create();

        return Enrollment::factory()->forClass($class)->forStudent($student)->create();
    }

    private function setExamFee(float $amount = 25.00): void
    {
        $this->tenant->update(['exam_fee_amount' => $amount]);
    }

    private function validPayload(Enrollment $enrollment): array
    {
        return [
            'enrollment_id' => $enrollment->id,
            'exam_date' => now()->addWeek()->toDateString(),
            'exam_time' => '09:00',
            'table_no' => '12',
            'has_paid' => true,
        ];
    }

    public function test_a_student_can_submit_an_exam_application_for_their_own_active_enrollment(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setExamFee(25.00);
        [$student, $user] = $this->studentWithUser();
        $enrollment = $this->activeEnrollment($student);
        $this->actingAsTenantUser($user);

        $response = $this->postJson('/api/v1/my-exam-applications', $this->validPayload($enrollment));

        $response->assertCreated();
        $response->assertJsonPath('data.status', ExamApplication::STATUS_PENDING);
        $response->assertJsonPath('data.fee_amount', '25.00');
        $response->assertJsonPath('data.fee_currency', Tenant::CURRENCY_USD);
        $this->assertSame(1, ExamApplication::where('student_id', $student->id)->count());
    }

    public function test_submitting_against_another_students_enrollment_fails(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setExamFee();
        [, $user] = $this->studentWithUser();
        [$otherStudent] = $this->studentWithUser();
        $otherEnrollment = $this->activeEnrollment($otherStudent);
        $this->actingAsTenantUser($user);

        $this->postJson('/api/v1/my-exam-applications', $this->validPayload($otherEnrollment))->assertUnprocessable();
    }

    public function test_submitting_against_a_dropped_enrollment_fails(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setExamFee();
        [$student, $user] = $this->studentWithUser();
        $class = SchoolClass::factory()->forTenant($this->tenant)->create();
        $enrollment = Enrollment::factory()->forClass($class)->forStudent($student)->dropped()->create();
        $this->actingAsTenantUser($user);

        $this->postJson('/api/v1/my-exam-applications', $this->validPayload($enrollment))->assertUnprocessable();
    }

    public function test_submitting_without_marking_paid_fails(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setExamFee();
        [$student, $user] = $this->studentWithUser();
        $enrollment = $this->activeEnrollment($student);
        $this->actingAsTenantUser($user);

        $payload = $this->validPayload($enrollment);
        $payload['has_paid'] = false;

        $this->postJson('/api/v1/my-exam-applications', $payload)->assertUnprocessable();
    }

    public function test_submitting_when_the_tenant_has_no_exam_fee_configured_fails(): void
    {
        $this->actingAsAdminWithPermissions([]);
        // Deliberately not calling setExamFee() — tenant.exam_fee_amount stays null.
        [$student, $user] = $this->studentWithUser();
        $enrollment = $this->activeEnrollment($student);
        $this->actingAsTenantUser($user);

        $this->postJson('/api/v1/my-exam-applications', $this->validPayload($enrollment))->assertUnprocessable();
    }

    public function test_the_fee_is_snapshotted_at_submission_and_unaffected_by_a_later_tenant_fee_change(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setExamFee(25.00);
        [$student, $user] = $this->studentWithUser();
        $enrollment = $this->activeEnrollment($student);
        $this->actingAsTenantUser($user);

        $this->postJson('/api/v1/my-exam-applications', $this->validPayload($enrollment))->assertCreated();

        $this->setExamFee(40.00);

        $application = ExamApplication::where('student_id', $student->id)->firstOrFail();
        $this->assertSame('25.00', (string) $application->fee_amount);
    }

    public function test_a_student_sees_only_their_own_exam_applications(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$student, $user] = $this->studentWithUser();
        [$otherStudent] = $this->studentWithUser();

        ExamApplication::factory()->forStudent($student)->forEnrollment($this->activeEnrollment($student))->create();
        ExamApplication::factory()->forStudent($otherStudent)->forEnrollment($this->activeEnrollment($otherStudent))->create();

        $this->actingAsTenantUser($user);
        $response = $this->getJson('/api/v1/my-exam-applications')->assertOk();

        $response->assertJsonCount(1, 'data');
    }

    public function test_viewing_the_admin_queue_requires_the_view_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/v1/exam-applications')->assertForbidden();
    }

    public function test_an_admin_with_permission_can_approve_a_pending_application(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_VIEW, Permissions::EXAM_APPLICATIONS_APPROVE]);
        [$student] = $this->studentWithUser();
        $application = ExamApplication::factory()->forStudent($student)->forEnrollment($this->activeEnrollment($student))->create();

        $response = $this->postJson("/api/v1/exam-applications/{$application->id}/approve");

        $response->assertOk();
        $response->assertJsonPath('data.status', ExamApplication::STATUS_APPROVED);
        $this->assertSame($admin->id, $application->fresh()->decided_by);
    }

    public function test_approving_an_already_decided_application_fails(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_APPROVE]);
        [$student] = $this->studentWithUser();
        $application = ExamApplication::factory()->forStudent($student)->forEnrollment($this->activeEnrollment($student))->create([
            'status' => ExamApplication::STATUS_APPROVED,
        ]);

        $this->postJson("/api/v1/exam-applications/{$application->id}/approve")->assertUnprocessable();
    }

    public function test_an_admin_with_permission_can_reject_with_a_reason(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_REJECT]);
        [$student] = $this->studentWithUser();
        $application = ExamApplication::factory()->forStudent($student)->forEnrollment($this->activeEnrollment($student))->create();

        $response = $this->postJson("/api/v1/exam-applications/{$application->id}/reject", ['reason' => 'Fee not verified']);

        $response->assertOk();
        $response->assertJsonPath('data.status', ExamApplication::STATUS_REJECTED);
        $response->assertJsonPath('data.decision_reason', 'Fee not verified');
        $this->assertSame($admin->id, $application->fresh()->decided_by);
    }

    public function test_rejecting_requires_the_reject_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$student] = $this->studentWithUser();
        $application = ExamApplication::factory()->forStudent($student)->forEnrollment($this->activeEnrollment($student))->create();

        $this->postJson("/api/v1/exam-applications/{$application->id}/reject", ['reason' => 'Not allowed'])->assertForbidden();
    }
}
