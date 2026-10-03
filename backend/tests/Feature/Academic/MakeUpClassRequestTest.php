<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Enrollment;
use App\Models\MakeUpClassRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Academic\MakeUpClassRequestService;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class MakeUpClassRequestTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private function studentWithUser(): array
    {
        $user = User::factory()->forTenant($this->tenant)->create();
        $student = Student::factory()->create(['user_id' => $user->id]);

        return [$student, $user];
    }

    private function payload(Student $student, array $overrides = []): array
    {
        $enrollment = Enrollment::factory()->forStudent($student)->create();

        return [
            'enrollment_id' => $enrollment->id,
            'from_date' => now()->toDateString(),
            'to_date' => now()->toDateString(),
            'from_time' => '08:00',
            'to_time' => '10:00',
            ...$overrides,
        ];
    }

    public function test_a_student_can_submit_a_make_up_class_request_and_it_starts_pending(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$student, $user] = $this->studentWithUser();
        $payload = $this->payload($student);
        $this->actingAsTenantUser($user);

        $response = $this->postJson('/api/v1/my-make-up-class-requests', $payload);

        $response->assertCreated();
        $response->assertJsonPath('data.status', MakeUpClassRequest::STATUS_PENDING);
        $response->assertJsonPath('data.from_time', '08:00');
        $response->assertJsonPath('data.to_time', '10:00');
        $response->assertJsonPath('data.enrollment_id', $payload['enrollment_id']);
        $this->assertSame(1, MakeUpClassRequest::where('student_id', $student->id)->count());
    }

    public function test_submitting_requires_a_linked_student_record(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $user = User::factory()->forTenant($this->tenant)->create();
        Staff::factory()->withUser($user)->create();
        $this->actingAsTenantUser($user);

        $this->postJson('/api/v1/my-make-up-class-requests', [
            'from_date' => now()->toDateString(),
            'to_date' => now()->toDateString(),
            'from_time' => '08:00',
            'to_time' => '10:00',
        ])->assertForbidden();
    }

    public function test_submitting_validates_the_date_and_time_ranges(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$student, $user] = $this->studentWithUser();
        $payload = $this->payload($student);
        $this->actingAsTenantUser($user);

        $this->postJson('/api/v1/my-make-up-class-requests', $this->withOverrides($payload, [
            'from_date' => now()->addDay()->toDateString(),
            'to_date' => now()->toDateString(),
            'from_time' => '10:00',
            'to_time' => '09:00',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['to_date', 'to_time']);

        $this->postJson('/api/v1/my-make-up-class-requests', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['enrollment_id', 'from_date', 'to_date', 'from_time', 'to_time']);
    }

    private function withOverrides(array $payload, array $overrides): array
    {
        return [...$payload, ...$overrides];
    }

    public function test_the_course_must_be_one_of_the_students_own_active_enrollments(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$student, $user] = $this->studentWithUser();
        [$otherStudent] = $this->studentWithUser();
        $payload = $this->payload($student);
        $othersEnrollment = Enrollment::factory()->forStudent($otherStudent)->create();
        $droppedEnrollment = Enrollment::factory()->forStudent($student)->dropped()->create();
        $this->actingAsTenantUser($user);

        foreach ([$othersEnrollment, $droppedEnrollment] as $enrollment) {
            $this->postJson('/api/v1/my-make-up-class-requests', [...$payload, 'enrollment_id' => $enrollment->id])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['enrollment_id']);
        }
    }

    public function test_the_enrollments_endpoint_lists_only_active_enrollments_newest_first(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$student, $user] = $this->studentWithUser();
        $older = Enrollment::factory()->forStudent($student)->create(['enrolled_at' => now()->subMonths(3)->toDateString()]);
        $newer = Enrollment::factory()->forStudent($student)->create(['enrolled_at' => now()->subWeek()->toDateString()]);
        Enrollment::factory()->forStudent($student)->dropped()->create();
        $this->actingAsTenantUser($user);

        $response = $this->getJson('/api/v1/my-make-up-class-requests/enrollments')->assertOk();

        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.id', $newer->id);
        $response->assertJsonPath('data.1.id', $older->id);
    }

    public function test_approving_to_study_only_changes_the_status_and_notifies_the_student(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::MAKE_UP_CLASS_REQUESTS_APPROVE]);
        [$student, $studentUser] = $this->studentWithUser();
        $makeUpClassRequest = MakeUpClassRequest::factory()->forStudent($student)->create();

        $response = $this->postJson("/api/v1/make-up-class-requests/{$makeUpClassRequest->id}/approve-to-study");

        $response->assertOk();
        $response->assertJsonPath('data.status', MakeUpClassRequest::STATUS_APPROVED_TO_STUDY);
        $this->assertSame($admin->id, $makeUpClassRequest->fresh()->decided_by);
        $this->assertSame(
            1,
            UserNotification::where('recipient_id', $studentUser->id)->where('type', NotificationType::MAKE_UP_CLASS_REQUEST_APPROVED_TO_STUDY)->count(),
        );
        // Not counted until the student actually came.
        $this->assertSame([], app(MakeUpClassRequestService::class)->approvedHoursByEnrollment([$makeUpClassRequest->enrollment_id]));

        // The student sees the new status on their own requests.
        $this->actingAsTenantUser($studentUser);
        $this->getJson('/api/v1/my-make-up-class-requests')->assertJsonPath('data.0.status', MakeUpClassRequest::STATUS_APPROVED_TO_STUDY);
    }

    public function test_approving_after_the_student_came_counts_the_hours_and_notifies_the_student(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::MAKE_UP_CLASS_REQUESTS_APPROVE]);
        [$student, $studentUser] = $this->studentWithUser();
        $makeUpClassRequest = MakeUpClassRequest::factory()->forStudent($student)->approvedToStudy()->create();

        $response = $this->postJson("/api/v1/make-up-class-requests/{$makeUpClassRequest->id}/approve");

        $response->assertOk();
        $response->assertJsonPath('data.status', MakeUpClassRequest::STATUS_APPROVED);
        $this->assertSame($admin->id, $makeUpClassRequest->fresh()->decided_by);
        $this->assertSame(
            1,
            UserNotification::where('recipient_id', $studentUser->id)->where('type', NotificationType::MAKE_UP_CLASS_REQUEST_APPROVED)->count(),
        );
        // One day, 08:00–10:00.
        $this->assertEquals(
            [$makeUpClassRequest->enrollment_id => 2.0],
            app(MakeUpClassRequestService::class)->approvedHoursByEnrollment([$makeUpClassRequest->enrollment_id]),
        );
    }

    public function test_a_pending_request_must_be_approved_to_study_before_it_can_be_approved(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::MAKE_UP_CLASS_REQUESTS_APPROVE]);
        [$student] = $this->studentWithUser();
        $makeUpClassRequest = MakeUpClassRequest::factory()->forStudent($student)->create();

        $this->postJson("/api/v1/make-up-class-requests/{$makeUpClassRequest->id}/approve")->assertUnprocessable();
        $this->assertSame(MakeUpClassRequest::STATUS_PENDING, $makeUpClassRequest->fresh()->status);
    }

    public function test_both_approvals_require_the_approve_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$student] = $this->studentWithUser();
        $pending = MakeUpClassRequest::factory()->forStudent($student)->create();
        $approvedToStudy = MakeUpClassRequest::factory()->forStudent($student)->approvedToStudy()->create();

        $this->postJson("/api/v1/make-up-class-requests/{$pending->id}/approve-to-study")->assertForbidden();
        $this->postJson("/api/v1/make-up-class-requests/{$approvedToStudy->id}/approve")->assertForbidden();
    }

    public function test_approving_an_already_decided_request_fails(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::MAKE_UP_CLASS_REQUESTS_APPROVE]);
        [$student] = $this->studentWithUser();
        $approved = MakeUpClassRequest::factory()->forStudent($student)->approved()->create();
        $approvedToStudy = MakeUpClassRequest::factory()->forStudent($student)->approvedToStudy()->create();

        $this->postJson("/api/v1/make-up-class-requests/{$approved->id}/approve")->assertUnprocessable();
        $this->postJson("/api/v1/make-up-class-requests/{$approved->id}/approve-to-study")->assertUnprocessable();
        $this->postJson("/api/v1/make-up-class-requests/{$approvedToStudy->id}/approve-to-study")->assertUnprocessable();
    }

    public function test_a_request_approved_to_study_can_still_be_rejected_when_the_student_did_not_come(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::MAKE_UP_CLASS_REQUESTS_REJECT]);
        [$student, $studentUser] = $this->studentWithUser();
        $makeUpClassRequest = MakeUpClassRequest::factory()->forStudent($student)->approvedToStudy()->create();

        $this->postJson("/api/v1/make-up-class-requests/{$makeUpClassRequest->id}/reject", ['reason' => 'Did not come'])
            ->assertOk()
            ->assertJsonPath('data.status', MakeUpClassRequest::STATUS_REJECTED)
            ->assertJsonPath('data.decision_reason', 'Did not come');
        $this->assertSame(
            1,
            UserNotification::where('recipient_id', $studentUser->id)->where('type', NotificationType::MAKE_UP_CLASS_REQUEST_REJECTED)->count(),
        );

        // Already rejected — no second rejection.
        $this->postJson("/api/v1/make-up-class-requests/{$makeUpClassRequest->id}/reject", ['reason' => 'Again'])->assertUnprocessable();
    }

    public function test_rejecting_a_make_up_class_request_records_a_reason(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::MAKE_UP_CLASS_REQUESTS_REJECT]);
        [$student] = $this->studentWithUser();
        $makeUpClassRequest = MakeUpClassRequest::factory()->forStudent($student)->create();

        $response = $this->postJson("/api/v1/make-up-class-requests/{$makeUpClassRequest->id}/reject", ['reason' => 'No teacher available']);

        $response->assertOk();
        $response->assertJsonPath('data.status', MakeUpClassRequest::STATUS_REJECTED);
        $response->assertJsonPath('data.decision_reason', 'No teacher available');
        $this->assertSame($admin->id, $makeUpClassRequest->fresh()->decided_by);
    }

    public function test_a_student_sees_only_their_own_make_up_class_requests(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$student, $user] = $this->studentWithUser();
        [$otherStudent] = $this->studentWithUser();

        $mine = MakeUpClassRequest::factory()->forStudent($student)->create();
        MakeUpClassRequest::factory()->forStudent($otherStudent)->create();

        $this->actingAsTenantUser($user);
        $response = $this->getJson('/api/v1/my-make-up-class-requests')->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_viewing_all_make_up_class_requests_requires_the_view_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/v1/make-up-class-requests')->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::MAKE_UP_CLASS_REQUESTS_VIEW]);
        $this->getJson('/api/v1/make-up-class-requests')->assertOk();
    }

    public function test_submitting_notifies_every_holder_of_the_approve_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$student, $studentUser] = $this->studentWithUser();
        $payload = $this->payload($student);

        $approverRole = Role::factory()->forTenant($this->tenant)->create(['slug' => 'test-make-up-approver', 'level' => 50]);
        $approverRole->permissions()->attach(Permission::query()->where('slug', Permissions::MAKE_UP_CLASS_REQUESTS_APPROVE)->firstOrFail());
        $approver = User::factory()->forTenant($this->tenant)->create();
        $approver->attachRoles($approverRole);

        // No approve permission — must not be notified.
        $bystander = User::factory()->forTenant($this->tenant)->create();

        $this->actingAsTenantUser($studentUser);
        $this->postJson('/api/v1/my-make-up-class-requests', $payload)->assertCreated();

        $this->assertSame(
            1,
            UserNotification::where('recipient_id', $approver->id)->where('type', NotificationType::MAKE_UP_CLASS_REQUEST_SUBMITTED)->count(),
        );
        $this->assertSame(0, UserNotification::where('recipient_id', $bystander->id)->count());
    }

    public function test_rejecting_a_make_up_class_request_notifies_the_student(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::MAKE_UP_CLASS_REQUESTS_REJECT]);
        [$student, $studentUser] = $this->studentWithUser();
        $makeUpClassRequest = MakeUpClassRequest::factory()->forStudent($student)->create();

        $this->postJson("/api/v1/make-up-class-requests/{$makeUpClassRequest->id}/reject", ['reason' => 'No teacher available'])->assertOk();

        $this->assertSame(
            1,
            UserNotification::where('recipient_id', $studentUser->id)->where('type', NotificationType::MAKE_UP_CLASS_REQUEST_REJECTED)->count(),
        );
    }
}
