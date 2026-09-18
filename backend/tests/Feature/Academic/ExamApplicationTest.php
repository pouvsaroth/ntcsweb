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
        $class = SchoolClass::factory()->create();

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
            'first_name' => 'Test',
            'last_name' => 'Student',
            'has_paid' => true,
        ];
    }

    public function test_a_student_can_apply_for_an_exam_against_their_own_active_enrollment(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setExamFee(25.00);
        [$student, $user] = $this->studentWithUser();
        $enrollment = $this->activeEnrollment($student);
        $this->actingAsTenantUser($user);

        $payload = $this->validPayload($enrollment);
        $payload['english_name'] = 'Tester';

        $response = $this->postJson('/api/v1/my-exam-applications', $payload);

        $response->assertCreated();
        $response->assertJsonPath('data.status', ExamApplication::STATUS_PENDING);
        $response->assertJsonPath('data.fee_amount', '25.00');
        $response->assertJsonPath('data.fee_currency', Tenant::CURRENCY_USD);
        // No exam-day logistics — the student never sets these, only a
        // teacher/admin does.
        $response->assertJsonPath('data.exam_date', null);
        $response->assertJsonPath('data.book', null);
        $this->assertSame(1, ExamApplication::where('student_id', $student->id)->count());

        // Personal-info edits are saved back to the real Student record.
        $this->assertSame('Test', $student->fresh()->first_name);
        $this->assertSame('Student', $student->fresh()->last_name);
        $this->assertSame('Tester', $student->fresh()->english_name);
    }

    public function test_applying_against_an_enrollment_a_teacher_already_sent_to_exam_keeps_the_exam_logistics(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setExamFee(25.00);
        [$student, $user] = $this->studentWithUser();
        $enrollment = $this->activeEnrollment($student);

        // "Send to Exam" creates a draft, not pending — see
        // SendToExamModal.vue/ExamApplicationService::applyOnline()'s docblock.
        $existing = ExamApplication::factory()->forStudent($student)->forEnrollment($enrollment)->create([
            'exam_date' => now()->addWeek()->toDateString(),
            'table_no' => 'A1',
            'status' => ExamApplication::STATUS_DRAFT,
        ]);

        $this->actingAsTenantUser($user);

        $response = $this->postJson('/api/v1/my-exam-applications', $this->validPayload($enrollment));

        $response->assertCreated();
        $response->assertJsonPath('data.status', ExamApplication::STATUS_PENDING);
        $this->assertSame(1, ExamApplication::where('enrollment_id', $enrollment->id)->count());

        $existing->refresh();
        $this->assertSame($existing->id, ExamApplication::where('enrollment_id', $enrollment->id)->firstOrFail()->id);
        // Applying moves draft -> pending — this is the "student actually
        // applied" moment the Approval tab is waiting for.
        $this->assertSame(ExamApplication::STATUS_PENDING, $existing->status);
        $this->assertSame('A1', $existing->table_no);
        $this->assertNotNull($existing->exam_date);
        $this->assertSame('25.00', (string) $existing->fee_amount);
        $this->assertSame('Test', $student->fresh()->first_name);
    }

    public function test_applying_against_an_already_decided_enrollment_fails(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setExamFee();
        [$student, $user] = $this->studentWithUser();
        $enrollment = $this->activeEnrollment($student);

        ExamApplication::factory()->forStudent($student)->forEnrollment($enrollment)->create([
            'status' => ExamApplication::STATUS_APPROVED,
        ]);

        $this->actingAsTenantUser($user);

        $this->postJson('/api/v1/my-exam-applications', $this->validPayload($enrollment))->assertUnprocessable();
    }

    public function test_applying_against_an_enrollment_marked_not_exam_fails(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setExamFee();
        [$student, $user] = $this->studentWithUser();
        $enrollment = $this->activeEnrollment($student);

        ExamApplication::factory()->forStudent($student)->forEnrollment($enrollment)->create([
            'status' => ExamApplication::STATUS_NOT_EXAM,
        ]);

        $this->actingAsTenantUser($user);

        $this->postJson('/api/v1/my-exam-applications', $this->validPayload($enrollment))->assertUnprocessable();
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
        $class = SchoolClass::factory()->create();
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

    public function test_the_lookup_endpoint_returns_the_students_own_info_and_any_existing_application(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$student, $user] = $this->studentWithUser();
        $enrollment = $this->activeEnrollment($student);

        ExamApplication::factory()->forStudent($student)->forEnrollment($enrollment)->create([
            'exam_date' => now()->addWeek()->toDateString(),
            'table_no' => 'A1',
            'status' => ExamApplication::STATUS_PENDING,
        ]);

        $this->actingAsTenantUser($user);

        $response = $this->getJson("/api/v1/my-exam-applications/lookup/{$enrollment->id}")->assertOk();

        $response->assertJsonPath('data.student.first_name', $student->first_name);
        $response->assertJsonPath('data.exam_application.table_no', 'A1');
    }

    public function test_the_lookup_endpoint_returns_a_null_application_when_none_exists_yet(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$student, $user] = $this->studentWithUser();
        $enrollment = $this->activeEnrollment($student);
        $this->actingAsTenantUser($user);

        $response = $this->getJson("/api/v1/my-exam-applications/lookup/{$enrollment->id}")->assertOk();

        $response->assertJsonPath('data.exam_application', null);
    }

    public function test_the_lookup_endpoint_rejects_another_students_enrollment(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [, $user] = $this->studentWithUser();
        [$otherStudent] = $this->studentWithUser();
        $otherEnrollment = $this->activeEnrollment($otherStudent);
        $this->actingAsTenantUser($user);

        $this->getJson("/api/v1/my-exam-applications/lookup/{$otherEnrollment->id}")->assertUnprocessable();
    }

    public function test_the_lookup_endpoint_rejects_an_already_decided_enrollment(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$student, $user] = $this->studentWithUser();
        $enrollment = $this->activeEnrollment($student);

        ExamApplication::factory()->forStudent($student)->forEnrollment($enrollment)->create([
            'status' => ExamApplication::STATUS_REJECTED,
        ]);

        $this->actingAsTenantUser($user);

        $this->getJson("/api/v1/my-exam-applications/lookup/{$enrollment->id}")->assertUnprocessable();
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

    public function test_a_draft_application_is_not_shown_in_the_students_own_list(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$student, $user] = $this->studentWithUser();

        ExamApplication::factory()->forStudent($student)->forEnrollment($this->activeEnrollment($student))->create([
            'status' => ExamApplication::STATUS_DRAFT,
        ]);

        $this->actingAsTenantUser($user);
        $response = $this->getJson('/api/v1/my-exam-applications')->assertOk();

        $response->assertJsonCount(0, 'data');
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
