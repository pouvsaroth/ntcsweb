<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Models\ApprovalRequest;
use App\Models\LeaveRequest;
use App\Models\MakeUpClassRequest;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * A requester deleting their own request from My Request while it is still
 * pending — leave, make-up class and generic approval requests.
 */
class WithdrawMyRequestTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private function studentWithUser(): array
    {
        $user = User::factory()->forTenant($this->tenant)->create();
        $student = Student::factory()->create(['user_id' => $user->id]);

        return [$student, $user];
    }

    public function test_a_student_can_delete_their_own_pending_leave_request(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$student, $user] = $this->studentWithUser();
        $leave = LeaveRequest::factory()->forStudent($student)->create();
        $this->actingAsTenantUser($user);

        $this->deleteJson("/api/v1/my-leave-requests/{$leave->id}")->assertNoContent();

        $this->assertSoftDeleted($leave);
    }

    public function test_a_decided_leave_request_cannot_be_deleted(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$student, $user] = $this->studentWithUser();
        $leave = LeaveRequest::factory()->forStudent($student)->create(['status' => LeaveRequest::STATUS_APPROVED]);
        $this->actingAsTenantUser($user);

        $this->deleteJson("/api/v1/my-leave-requests/{$leave->id}")
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $this->assertNotSoftDeleted($leave);
    }

    public function test_a_student_cannot_delete_someone_elses_leave_request(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [, $user] = $this->studentWithUser();
        $leave = LeaveRequest::factory()->create();
        $this->actingAsTenantUser($user);

        $this->deleteJson("/api/v1/my-leave-requests/{$leave->id}")->assertNotFound();

        $this->assertNotSoftDeleted($leave);
    }

    public function test_a_student_can_delete_their_own_pending_make_up_class_request_only(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$student, $user] = $this->studentWithUser();
        $pending = MakeUpClassRequest::factory()->forStudent($student)->create();
        $approvedToStudy = MakeUpClassRequest::factory()->forStudent($student)->approvedToStudy()->create();
        $someoneElses = MakeUpClassRequest::factory()->create();
        $this->actingAsTenantUser($user);

        $this->deleteJson("/api/v1/my-make-up-class-requests/{$pending->id}")->assertNoContent();
        $this->deleteJson("/api/v1/my-make-up-class-requests/{$approvedToStudy->id}")
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->deleteJson("/api/v1/my-make-up-class-requests/{$someoneElses->id}")->assertNotFound();

        $this->assertSoftDeleted($pending);
        $this->assertNotSoftDeleted($approvedToStudy);
        $this->assertNotSoftDeleted($someoneElses);
    }

    public function test_a_user_can_delete_their_own_pending_approval_request_only(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $user = User::factory()->forTenant($this->tenant)->create();
        $pending = ApprovalRequest::factory()->create(['requested_by' => $user->id]);
        $rejected = ApprovalRequest::factory()->create(['requested_by' => $user->id, 'status' => ApprovalRequest::STATUS_REJECTED]);
        $someoneElses = ApprovalRequest::factory()->create();
        $this->actingAsTenantUser($user);

        $this->deleteJson("/api/v1/my-approval-requests/{$pending->id}")->assertNoContent();
        $this->deleteJson("/api/v1/my-approval-requests/{$rejected->id}")
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->deleteJson("/api/v1/my-approval-requests/{$someoneElses->id}")->assertNotFound();

        $this->assertSoftDeleted($pending);
        $this->assertNotSoftDeleted($rejected);
        $this->assertNotSoftDeleted($someoneElses);
    }
}
