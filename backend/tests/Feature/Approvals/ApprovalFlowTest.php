<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Models\ApprovalGroup;
use App\Models\Enrollment;
use App\Models\LeaveRequest;
use App\Models\MakeUpClassRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Approvals\DocumentType;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * Approval Flow → Flow Setting — see ApprovalFlowController and
 * App\Services\Approvals\ApprovalFlow. The scenario throughout: student
 * permission requests go to group 1 (A and B), then group 2 (C). D holds
 * the leave approve/reject permissions but is in neither group.
 */
class ApprovalFlowTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private User $manager;

    private User $a;

    private User $b;

    private User $c;

    private User $d;

    private ApprovalGroup $group1;

    private ApprovalGroup $group2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->actingAsAdminWithPermissions([Permissions::APPROVAL_GROUPS_MANAGE]);
        [$this->a, $this->b, $this->c] = User::factory()->forTenant($this->tenant)->count(3)->create()->all();

        $this->group1 = ApprovalGroup::factory()->create(['name' => 'Group 1']);
        $this->group1->members()->create(['user_id' => $this->a->id]);
        $this->group1->members()->create(['user_id' => $this->b->id]);
        $this->group2 = ApprovalGroup::factory()->create(['name' => 'Group 2']);
        $this->group2->members()->create(['user_id' => $this->c->id]);

        $role = Role::factory()->forTenant($this->tenant)->create(['slug' => 'test-leave-approver', 'level' => 50]);
        $role->permissions()->attach(Permission::query()->whereIn('slug', [
            Permissions::LEAVE_REQUESTS_VIEW, Permissions::LEAVE_REQUESTS_APPROVE, Permissions::LEAVE_REQUESTS_REJECT,
        ])->pluck('id'));
        $this->d = User::factory()->forTenant($this->tenant)->create();
        $this->d->attachRoles($role);
    }

    private function setStudentLeaveFlow(array $groupIds): void
    {
        $this->actingAsTenantUser($this->manager);
        $this->putJson('/api/v1/approval-flows/'.DocumentType::STUDENT_LEAVE, ['group_ids' => $groupIds])->assertOk();
    }

    /** @return list<int> ids the user sees in the Approvals queue's New tab */
    private function pendingQueueIds(User $user): array
    {
        $this->actingAsTenantUser($user);

        return collect($this->getJson('/api/v1/leave-requests?approval_queue=1')->assertOk()->json('data'))
            ->where('status', 'pending')->pluck('id')->all();
    }

    public function test_the_flow_setting_lists_every_item_and_saves_steps_in_order(): void
    {
        $this->setStudentLeaveFlow([$this->group2->id, $this->group1->id]);

        $response = $this->getJson('/api/v1/approval-flows')->assertOk();

        $this->assertSame(DocumentType::all(), collect($response->json('data'))->pluck('document_type')->all());
        $studentLeave = collect($response->json('data'))->firstWhere('document_type', DocumentType::STUDENT_LEAVE);
        $this->assertSame(['Group 2', 'Group 1'], collect($studentLeave['steps'])->pluck('group.name')->all());
        $this->assertSame(2, $studentLeave['steps'][1]['group']['member_count']);
    }

    public function test_managing_flows_requires_the_manage_permission(): void
    {
        $this->actingAsTenantUser($this->d);

        $this->getJson('/api/v1/approval-flows')->assertForbidden();
        $this->putJson('/api/v1/approval-flows/'.DocumentType::STUDENT_LEAVE, ['group_ids' => []])->assertForbidden();
    }

    public function test_an_unknown_item_or_group_is_rejected(): void
    {
        $this->putJson('/api/v1/approval-flows/nothing', ['group_ids' => []])->assertNotFound();
        $this->putJson('/api/v1/approval-flows/'.DocumentType::STUDENT_LEAVE, ['group_ids' => [99999]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['group_ids.0']);
    }

    public function test_each_step_is_approved_by_one_member_of_its_group_in_order(): void
    {
        $this->setStudentLeaveFlow([$this->group1->id, $this->group2->id]);
        $studentUser = User::factory()->forTenant($this->tenant)->create();
        $request = LeaveRequest::factory()->forStudent(Student::factory()->create(['user_id' => $studentUser->id]))->create();

        // Step 1 waits on group 1 only.
        $this->assertSame([$request->id], $this->pendingQueueIds($this->a));
        $this->assertSame([$request->id], $this->pendingQueueIds($this->b));
        $this->assertSame([], $this->pendingQueueIds($this->c));
        $this->assertSame([], $this->pendingQueueIds($this->d));
        $this->postJson("/api/v1/leave-requests/{$request->id}/approve")->assertForbidden();

        $this->actingAsTenantUser($this->a);
        $this->postJson("/api/v1/leave-requests/{$request->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_PENDING);

        // A approved step 1 — B now has nothing to approve; group 2 is told and sees it.
        $this->assertSame([], $this->pendingQueueIds($this->b));
        $this->postJson("/api/v1/leave-requests/{$request->id}/approve")->assertForbidden();
        $this->assertSame(1, UserNotification::where('recipient_id', $this->c->id)->where('type', NotificationType::LEAVE_REQUEST_SUBMITTED)->count());

        $queue = collect($this->actingAsTenantUser($this->c)->getJson('/api/v1/leave-requests?approval_queue=1')->json('data'));
        $this->assertSame(['step' => 2, 'total' => 2, 'group' => 'Group 2', 'can_act' => true], $queue->firstWhere('id', $request->id)['approval_flow']);

        $this->postJson("/api/v1/leave-requests/{$request->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_APPROVED);
        $this->assertSame($this->c->id, $request->fresh()->decided_by);
        $this->assertSame(1, UserNotification::where('recipient_id', $studentUser->id)->where('type', NotificationType::LEAVE_REQUEST_APPROVED)->count());
    }

    public function test_the_requester_is_told_each_time_a_step_is_approved(): void
    {
        $this->setStudentLeaveFlow([$this->group1->id, $this->group2->id]);
        $studentUser = User::factory()->forTenant($this->tenant)->create();
        $request = LeaveRequest::factory()->forStudent(Student::factory()->create(['user_id' => $studentUser->id]))->create();

        $this->actingAsTenantUser($this->a);
        $this->postJson("/api/v1/leave-requests/{$request->id}/approve")->assertOk();

        $stepNotice = UserNotification::where('recipient_id', $studentUser->id)->where('type', NotificationType::APPROVAL_STEP_APPROVED)->sole();
        $this->assertSame(1, $stepNotice->data['step']);
        $this->assertSame(2, $stepNotice->data['total']);
        $this->assertSame($this->a->name, $stepNotice->data['approver_name']);

        // The last step sends the item's own "approved", not another step notice.
        $this->actingAsTenantUser($this->c);
        $this->postJson("/api/v1/leave-requests/{$request->id}/approve")->assertOk();

        $this->assertSame(1, UserNotification::where('recipient_id', $studentUser->id)->where('type', NotificationType::APPROVAL_STEP_APPROVED)->count());
        $this->assertSame(1, UserNotification::where('recipient_id', $studentUser->id)->where('type', NotificationType::LEAVE_REQUEST_APPROVED)->count());
    }

    public function test_the_requester_is_told_when_a_step_rejects(): void
    {
        $this->setStudentLeaveFlow([$this->group1->id, $this->group2->id]);
        $studentUser = User::factory()->forTenant($this->tenant)->create();
        $request = LeaveRequest::factory()->forStudent(Student::factory()->create(['user_id' => $studentUser->id]))->create();

        $this->actingAsTenantUser($this->a);
        $this->postJson("/api/v1/leave-requests/{$request->id}/approve")->assertOk();
        $this->actingAsTenantUser($this->c);
        $this->postJson("/api/v1/leave-requests/{$request->id}/reject", ['reason' => 'No seats that day'])->assertOk();

        $notice = UserNotification::where('recipient_id', $studentUser->id)->where('type', NotificationType::LEAVE_REQUEST_REJECTED)->sole();
        $this->assertSame('No seats that day', $notice->data['reason']);
    }

    public function test_a_make_up_class_is_approved_to_study_by_the_flow_then_approved_by_its_last_group(): void
    {
        $this->actingAsTenantUser($this->manager);
        $this->putJson('/api/v1/approval-flows/'.DocumentType::MAKE_UP_CLASS, ['group_ids' => [$this->group1->id, $this->group2->id]])->assertOk();
        $request = MakeUpClassRequest::factory()->create();

        $this->actingAsTenantUser($this->a);
        $this->postJson("/api/v1/make-up-class-requests/{$request->id}/approve-to-study")
            ->assertOk()
            ->assertJsonPath('data.status', MakeUpClassRequest::STATUS_PENDING);

        $this->actingAsTenantUser($this->c);
        $this->postJson("/api/v1/make-up-class-requests/{$request->id}/approve-to-study")
            ->assertOk()
            ->assertJsonPath('data.status', MakeUpClassRequest::STATUS_APPROVED_TO_STUDY);

        // The last step's group still decides it once the student came — and sees it waiting on them.
        $queue = collect($this->getJson('/api/v1/make-up-class-requests?approval_queue=1')->assertOk()->json('data'));
        $this->assertSame(['step' => 2, 'total' => 2, 'group' => 'Group 2', 'can_act' => true], $queue->firstWhere('id', $request->id)['approval_flow']);

        $this->actingAsTenantUser($this->a);
        $this->postJson("/api/v1/make-up-class-requests/{$request->id}/approve")->assertForbidden();

        $this->actingAsTenantUser($this->c);
        $this->postJson("/api/v1/make-up-class-requests/{$request->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', MakeUpClassRequest::STATUS_APPROVED);
    }

    public function test_only_the_current_steps_group_can_reject(): void
    {
        $this->setStudentLeaveFlow([$this->group1->id, $this->group2->id]);
        $request = LeaveRequest::factory()->create();

        $this->actingAsTenantUser($this->d);
        $this->postJson("/api/v1/leave-requests/{$request->id}/reject", ['reason' => 'No'])->assertForbidden();
        $this->actingAsTenantUser($this->c);
        $this->postJson("/api/v1/leave-requests/{$request->id}/reject", ['reason' => 'No'])->assertForbidden();

        $this->actingAsTenantUser($this->b);
        $this->postJson("/api/v1/leave-requests/{$request->id}/reject", ['reason' => 'Not enough notice'])
            ->assertOk()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_REJECTED);
    }

    public function test_submitting_notifies_the_first_steps_group_instead_of_permission_holders(): void
    {
        $this->setStudentLeaveFlow([$this->group1->id, $this->group2->id]);
        $studentUser = User::factory()->forTenant($this->tenant)->create();
        $student = Student::factory()->create(['user_id' => $studentUser->id]);
        $enrollment = Enrollment::factory()->forStudent($student)->create();

        $this->actingAsTenantUser($studentUser);
        $this->postJson('/api/v1/my-leave-requests', [
            'enrollment_id' => $enrollment->id,
            'from_date' => now()->addDay()->toDateString(),
            'to_date' => now()->addDay()->toDateString(),
            'reason' => 'Family event',
        ])->assertCreated();

        foreach ([$this->a, $this->b] as $member) {
            $this->assertSame(1, UserNotification::where('recipient_id', $member->id)->where('type', NotificationType::LEAVE_REQUEST_SUBMITTED)->count());
        }
        $this->assertSame(0, UserNotification::where('recipient_id', $this->c->id)->count());
        $this->assertSame(0, UserNotification::where('recipient_id', $this->d->id)->count());
    }

    public function test_a_staff_leave_request_is_not_affected_by_the_student_flow(): void
    {
        $this->setStudentLeaveFlow([$this->group1->id]);
        $staff = Staff::factory()->withUser(User::factory()->forTenant($this->tenant)->create())->create();
        $request = LeaveRequest::factory()->create(['student_id' => null, 'staff_id' => $staff->id]);

        $this->assertSame([], $this->pendingQueueIds($this->a));
        $this->assertSame([$request->id], $this->pendingQueueIds($this->d));

        $this->actingAsTenantUser($this->d);
        $this->postJson("/api/v1/leave-requests/{$request->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_APPROVED);
    }

    public function test_changing_a_flow_moves_waiting_requests_onto_it(): void
    {
        $this->setStudentLeaveFlow([$this->group1->id]);
        $request = LeaveRequest::factory()->create();
        $this->assertSame([$request->id], $this->pendingQueueIds($this->a));

        $this->setStudentLeaveFlow([$this->group2->id]);

        // A is in no leave flow any more and has no leave permission.
        $this->actingAsTenantUser($this->a);
        $this->getJson('/api/v1/leave-requests?approval_queue=1')->assertForbidden();
        $this->assertSame([$request->id], $this->pendingQueueIds($this->c));
    }

    public function test_removing_a_flow_brings_back_the_permission_rule(): void
    {
        $this->setStudentLeaveFlow([$this->group1->id]);
        $this->setStudentLeaveFlow([]);
        $request = LeaveRequest::factory()->create();

        $this->actingAsTenantUser($this->a);
        $this->getJson('/api/v1/leave-requests')->assertForbidden();

        $this->actingAsTenantUser($this->d);
        $this->postJson("/api/v1/leave-requests/{$request->id}/approve")->assertOk();
    }

    public function test_a_group_used_in_a_flow_cannot_be_deleted(): void
    {
        $this->setStudentLeaveFlow([$this->group1->id]);

        $this->deleteJson("/api/v1/approval-groups/{$this->group1->id}")->assertUnprocessable();
        $this->deleteJson("/api/v1/approval-groups/{$this->group2->id}")->assertNoContent();
    }

    public function test_me_lists_the_items_the_user_approves_a_step_of(): void
    {
        $this->setStudentLeaveFlow([$this->group1->id]);

        $this->actingAsTenantUser($this->a);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.approval_flow_types', [DocumentType::STUDENT_LEAVE]);

        $this->actingAsTenantUser($this->c);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.approval_flow_types', []);
    }
}
