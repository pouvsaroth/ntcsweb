<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AcademicProgram;
use App\Models\ClassChangeRequest;
use App\Models\Enrollment;
use App\Models\EnrollmentTransferHistory;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * A student's request to change class: submitted from My Request, decided in
 * Approvals; approving moves the same enrollment and writes the history.
 */
class ClassChangeRequestTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private User $studentUser;

    private Student $student;

    private Enrollment $enrollment;

    private SchoolClass $from;

    private SchoolClass $to;

    private function school(): void
    {
        $this->actingAsAdminWithPermissions([
            Permissions::CLASS_CHANGE_REQUESTS_VIEW, Permissions::CLASS_CHANGE_REQUESTS_APPROVE, Permissions::CLASS_CHANGE_REQUESTS_REJECT,
        ]);
        $this->from = SchoolClass::factory()->create(['name' => 'Morning A', 'status' => SchoolClass::STATUS_ACTIVE]);
        // Another program: a class change request may pick any open class.
        $other = AcademicProgram::factory()->create();
        $this->to = SchoolClass::factory()->create(['name' => 'Evening B', 'status' => SchoolClass::STATUS_UPCOMING, 'academic_program_id' => $other->id]);
        SchoolClass::factory()->create(['name' => 'Closed C', 'status' => SchoolClass::STATUS_CANCELLED]);

        $this->studentUser = User::factory()->forTenant($this->tenant)->create();
        $this->student = Student::factory()->create(['user_id' => $this->studentUser->id]);
        $this->enrollment = Enrollment::factory()->forClass($this->from)->forStudent($this->student)->create([
            'status' => Enrollment::STATUS_ACTIVE, 'enrolled_at' => '2026-09-01',
        ]);
    }

    private function submit(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/my-class-change-requests', [
            'enrollment_id' => $this->enrollment->id, 'to_class_id' => $this->to->id, 'start_date' => '2026-11-02', 'reason' => 'Work hours changed',
            ...$overrides,
        ]);
    }

    public function test_a_student_asks_and_an_approver_moves_the_same_enrollment_with_history(): void
    {
        $this->school();
        $admin = $this->admin;

        $this->actingAsTenantUser($this->studentUser);
        $options = $this->getJson('/api/v1/my-class-change-requests/options')->assertOk()->json('data');
        $this->assertSame([$this->enrollment->id], array_column($options['enrollments'], 'id'));
        $names = array_column($options['classes'], 'name');
        $this->assertContains('Evening B', $names);
        $this->assertNotContains('Closed C', $names);

        $created = $this->submit()->assertCreated()->json('data');
        $this->assertSame(['pending', 'Morning A', 'Evening B', '2026-11-02'], [$created['status'], $created['from_class']['name'], $created['to_class']['name'], $created['start_date']]);
        // One waiting request per enrollment; not into the current class.
        $this->submit()->assertUnprocessable()->assertJsonValidationErrors('enrollment_id');
        $this->assertSame(1, UserNotification::query()->where('recipient_id', $admin->id)->where('type', NotificationType::CLASS_CHANGE_REQUEST_SUBMITTED)->count());

        $this->actingAsTenantUser($admin);
        $queue = $this->getJson('/api/v1/class-change-requests')->assertOk()->json('data');
        $this->assertTrue($queue[0]['can_approve']);
        $this->postJson("/api/v1/class-change-requests/{$created['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        // Same enrollment, same date and course: only the class changed.
        $e = $this->enrollment->fresh();
        $this->assertSame($this->to->id, (int) $e->class_id);
        $this->assertSame('2026-09-01', $e->enrolled_at->toDateString());
        $this->assertSame($this->enrollment->course_package_id, $e->course_package_id);
        $this->assertSame($this->enrollment->enrollments_code, $e->enrollments_code);
        $this->assertSame(1, Enrollment::query()->where('student_id', $this->student->id)->count());

        $history = EnrollmentTransferHistory::query()->where('enrollment_id', $e->id)->sole();
        $this->assertSame([$this->from->id, $this->to->id, $created['id'], $admin->id], [(int) $history->from_class_id, (int) $history->to_class_id, (int) $history->class_change_request_id, (int) $history->changed_by]);
        $this->assertSame('2026-11-02', substr((string) $history->start_date, 0, 10));
        $this->assertSame(1, UserNotification::query()->where('recipient_id', $this->studentUser->id)->where('type', NotificationType::CLASS_CHANGE_REQUEST_APPROVED)->count());

        // Decided once.
        $this->postJson("/api/v1/class-change-requests/{$created['id']}/approve")->assertUnprocessable();
    }

    public function test_reject_needs_a_reason_and_leaves_the_class(): void
    {
        $this->school();
        $admin = $this->admin;
        $this->actingAsTenantUser($this->studentUser);
        $id = $this->submit()->json('data.id');

        $this->actingAsTenantUser($admin);
        $this->postJson("/api/v1/class-change-requests/{$id}/reject")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/class-change-requests/{$id}/reject", ['reason' => 'Class is full'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame($this->from->id, (int) $this->enrollment->fresh()->class_id);
        $this->assertSame(0, EnrollmentTransferHistory::query()->count());

        $this->actingAsTenantUser($this->studentUser);
        $mine = $this->getJson('/api/v1/my-class-change-requests')->assertOk()->json('data.0');
        $this->assertSame(['rejected', 'Class is full'], [$mine['status'], $mine['decision_reason']]);
    }

    public function test_checks_on_submit_withdraw_and_who_decides(): void
    {
        $this->school();
        $this->actingAsTenantUser($this->studentUser);
        $this->submit(['to_class_id' => $this->from->id])->assertUnprocessable()->assertJsonValidationErrors('to_class_id');
        $closed = SchoolClass::query()->where('name', 'Closed C')->firstOrFail();
        $this->submit(['to_class_id' => $closed->id])->assertUnprocessable()->assertJsonValidationErrors('to_class_id');
        $someoneElses = Enrollment::factory()->forClass($this->from)->create(['status' => Enrollment::STATUS_ACTIVE]);
        $this->submit(['enrollment_id' => $someoneElses->id])->assertUnprocessable()->assertJsonValidationErrors('enrollment_id');

        $id = $this->submit()->assertCreated()->json('data.id');

        // No approve permission: cannot decide.
        $this->actingAsAdminWithPermissions([Permissions::CLASS_CHANGE_REQUESTS_VIEW]);
        $this->postJson("/api/v1/class-change-requests/{$id}/approve")->assertForbidden();

        // The student withdraws it while it waits.
        $this->actingAsTenantUser($this->studentUser);
        $this->deleteJson("/api/v1/my-class-change-requests/{$id}")->assertNoContent();
        $this->assertSame(0, ClassChangeRequest::query()->count());
    }
}
