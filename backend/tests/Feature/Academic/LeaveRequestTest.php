<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AttendanceRecord;
use App\Models\ClassSchedule;
use App\Models\Enrollment;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestAttachment;
use App\Models\Permission;
use App\Models\Position;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Academic\AttendanceStatus;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class LeaveRequestTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private function studentWithUser(): array
    {
        $user = User::factory()->forTenant($this->tenant)->create();
        $student = Student::factory()->create(['user_id' => $user->id]);

        return [$student, $user];
    }

    public function test_a_student_can_submit_a_leave_request_with_an_attachment_and_it_starts_pending(): void
    {
        Storage::fake('public');
        $this->actingAsAdminWithPermissions([]);
        [$student, $user] = $this->studentWithUser();
        $enrollment = Enrollment::factory()->forStudent($student)->create();
        $this->actingAsTenantUser($user);

        $response = $this->post('/api/v1/my-leave-requests', [
            'enrollment_id' => $enrollment->id,
            'from_date' => now()->addDay()->toDateString(),
            'to_date' => now()->addDays(2)->toDateString(),
            'reason' => 'Family event out of town',
            'attachments' => [UploadedFile::fake()->image('doctor-note.jpg')],
        ], ['Accept' => 'application/json']);

        $response->assertCreated();
        $response->assertJsonPath('data.status', LeaveRequest::STATUS_PENDING);
        $response->assertJsonCount(1, 'data.attachments');
        $response->assertJsonPath('data.enrollment_id', $enrollment->id);
        $this->assertSame(1, LeaveRequest::where('student_id', $student->id)->count());
    }

    public function test_submitting_a_leave_request_requires_a_linked_student_record(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $user = User::factory()->forTenant($this->tenant)->create();
        $this->actingAsTenantUser($user);

        $this->postJson('/api/v1/my-leave-requests', [
            'from_date' => now()->addDay()->toDateString(),
            'to_date' => now()->addDay()->toDateString(),
            'reason' => 'No student record',
        ])->assertForbidden();
    }

    public function test_approving_a_leave_request_marks_every_matching_class_day_as_excused_attendance(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::LEAVE_REQUESTS_APPROVE]);
        [$student] = $this->studentWithUser();

        $class = SchoolClass::factory()->create();
        ClassSchedule::factory()->forClass($class)->onDay(ClassSchedule::MONDAY)->create();
        $enrollment = Enrollment::factory()->forClass($class)->forStudent($student)->create();

        $firstMonday = Carbon::now()->next(Carbon::MONDAY);
        $secondMonday = $firstMonday->copy()->addWeek();

        $leaveRequest = LeaveRequest::factory()->forStudent($student)->create([
            'from_date' => $firstMonday->toDateString(),
            'to_date' => $secondMonday->toDateString(),
        ]);

        $response = $this->postJson("/api/v1/leave-requests/{$leaveRequest->id}/approve");

        $response->assertOk();
        $response->assertJsonPath('data.status', LeaveRequest::STATUS_APPROVED);
        $this->assertSame(LeaveRequest::STATUS_APPROVED, $leaveRequest->fresh()->status);
        $this->assertSame($admin->id, $leaveRequest->fresh()->decided_by);

        $records = AttendanceRecord::where('enrollment_id', $enrollment->id)->get();
        $this->assertCount(2, $records);
        $this->assertTrue($records->every(fn (AttendanceRecord $record) => $record->status === AttendanceStatus::EXCUSED));
        $this->assertEqualsCanonicalizing(
            [$firstMonday->toDateString(), $secondMonday->toDateString()],
            $records->pluck('date')->map(fn ($date) => $date->toDateString())->all(),
        );
    }

    public function test_approving_a_leave_request_after_attendance_was_taken_changes_absent_to_excused(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::LEAVE_REQUESTS_APPROVE, Permissions::ATTENDANCE_CREATE, Permissions::ATTENDANCE_UPDATE]);
        [$student] = $this->studentWithUser();

        $class = SchoolClass::factory()->create();
        ClassSchedule::factory()->forClass($class)->onDay(ClassSchedule::MONDAY)->create();
        $enrollment = Enrollment::factory()->forClass($class)->forStudent($student)->create();
        $lastMonday = Carbon::now()->previous(Carbon::MONDAY)->toDateString();

        $this->postJson("/api/v1/classes/{$class->id}/attendance", [
            'date' => $lastMonday,
            'entries' => [['enrollment_id' => $enrollment->id, 'status' => AttendanceStatus::ABSENT]],
        ])->assertSuccessful();

        $leaveRequest = LeaveRequest::factory()->forStudent($student)->create(['from_date' => $lastMonday, 'to_date' => $lastMonday]);

        $this->postJson("/api/v1/leave-requests/{$leaveRequest->id}/approve")->assertOk();

        $record = AttendanceRecord::where('enrollment_id', $enrollment->id)->sole();
        $this->assertSame(AttendanceStatus::EXCUSED, $record->status);
        $this->assertSame($lastMonday, $record->date->toDateString());
    }

    public function test_approving_a_leave_request_requires_the_approve_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$student] = $this->studentWithUser();
        $leaveRequest = LeaveRequest::factory()->forStudent($student)->create();

        $this->postJson("/api/v1/leave-requests/{$leaveRequest->id}/approve")->assertForbidden();
    }

    public function test_approving_an_already_decided_leave_request_fails(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::LEAVE_REQUESTS_APPROVE]);
        [$student] = $this->studentWithUser();
        $leaveRequest = LeaveRequest::factory()->forStudent($student)->create([
            'status' => LeaveRequest::STATUS_APPROVED,
        ]);

        $this->postJson("/api/v1/leave-requests/{$leaveRequest->id}/approve")->assertUnprocessable();
    }

    public function test_rejecting_a_leave_request_records_a_reason_and_leaves_attendance_untouched(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::LEAVE_REQUESTS_REJECT]);
        [$student] = $this->studentWithUser();
        $class = SchoolClass::factory()->create();
        ClassSchedule::factory()->forClass($class)->onDay(ClassSchedule::MONDAY)->create();
        Enrollment::factory()->forClass($class)->forStudent($student)->create();

        $leaveRequest = LeaveRequest::factory()->forStudent($student)->create([
            'from_date' => Carbon::now()->next(Carbon::MONDAY)->toDateString(),
            'to_date' => Carbon::now()->next(Carbon::MONDAY)->toDateString(),
        ]);

        $response = $this->postJson("/api/v1/leave-requests/{$leaveRequest->id}/reject", ['reason' => 'Not enough notice']);

        $response->assertOk();
        $response->assertJsonPath('data.status', LeaveRequest::STATUS_REJECTED);
        $response->assertJsonPath('data.decision_reason', 'Not enough notice');
        $this->assertSame($admin->id, $leaveRequest->fresh()->decided_by);
        $this->assertSame(0, AttendanceRecord::count());
    }

    public function test_a_student_sees_only_their_own_leave_requests(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$student, $user] = $this->studentWithUser();
        [$otherStudent] = $this->studentWithUser();

        LeaveRequest::factory()->forStudent($student)->create(['reason' => 'Mine']);
        LeaveRequest::factory()->forStudent($otherStudent)->create(['reason' => 'Not mine']);

        $this->actingAsTenantUser($user);
        $response = $this->getJson('/api/v1/my-leave-requests')->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.reason', 'Mine');
    }

    public function test_viewing_all_leave_requests_requires_the_view_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/v1/leave-requests')->assertForbidden();
    }

    /**
     * Regression guard: index() used to eager-load student/staff/decidedBy
     * but not attachments, so LeaveRequestResource's whenLoaded('attachments')
     * had nothing to resolve here even though show() (a single request)
     * always got it right — a reviewer working from the list (the Approval
     * queue's own detail view reads straight off this list, not a per-row
     * show() call) never saw an attachment a student had actually uploaded.
     */
    public function test_viewing_all_leave_requests_includes_each_ones_attachments(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::LEAVE_REQUESTS_VIEW]);
        [$student] = $this->studentWithUser();
        $leaveRequest = LeaveRequest::factory()->create(['student_id' => $student->id]);
        $attachment = LeaveRequestAttachment::factory()->create([
            'leave_request_id' => $leaveRequest->id,
            'file_name' => 'doctor-note.jpg',
        ]);

        $response = $this->getJson('/api/v1/leave-requests');

        $response->assertOk();
        $response->assertJsonCount(1, 'data.0.attachments');
        $response->assertJsonPath('data.0.attachments.0.id', $attachment->id);
        $response->assertJsonPath('data.0.attachments.0.file_name', 'doctor-note.jpg');
    }

    public function test_submitting_a_leave_request_notifies_every_holder_of_the_approve_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$student, $studentUser] = $this->studentWithUser();
        $enrollment = Enrollment::factory()->forStudent($student)->create();

        $approverRole = Role::factory()->forTenant($this->tenant)->create(['slug' => 'test-approver', 'level' => 50]);
        $approverRole->permissions()->attach(Permission::query()->where('slug', Permissions::LEAVE_REQUESTS_APPROVE)->firstOrFail());
        $approver = User::factory()->forTenant($this->tenant)->create();
        $approver->attachRoles($approverRole);

        // No approve permission — must not be notified.
        $bystander = User::factory()->forTenant($this->tenant)->create();

        $this->actingAsTenantUser($studentUser);
        $this->postJson('/api/v1/my-leave-requests', [
            'enrollment_id' => $enrollment->id,
            'from_date' => now()->addDay()->toDateString(),
            'to_date' => now()->addDay()->toDateString(),
            'reason' => 'Family event',
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(
            1,
            UserNotification::where('recipient_id', $approver->id)->where('type', NotificationType::LEAVE_REQUEST_SUBMITTED)->count(),
        );
        $this->assertSame(0, UserNotification::where('recipient_id', $bystander->id)->count());
    }

    public function test_approving_a_leave_request_notifies_the_student_their_teacher_and_staff(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::LEAVE_REQUESTS_APPROVE]);
        [$student, $studentUser] = $this->studentWithUser();

        $teacherPosition = Position::factory()->create(['name' => 'Teacher']);
        $teacherUser = User::factory()->forTenant($this->tenant)->create();
        $teacher = Staff::factory()->withUser($teacherUser)->create(['position_id' => $teacherPosition->id]);
        $class = SchoolClass::factory()->withTeacher($teacher)->create();
        Enrollment::factory()->forClass($class)->forStudent($student)->create();

        $staffRole = Role::factory()->forTenant($this->tenant)->create(['slug' => Role::STAFF, 'name' => 'Staff', 'level' => Role::LEVELS[Role::STAFF]]);
        $staffUser = User::factory()->forTenant($this->tenant)->create();
        $staffUser->attachRoles($staffRole);

        // Neither teaching this student nor Staff-role — must not be notified.
        $bystander = User::factory()->forTenant($this->tenant)->create();

        $leaveRequest = LeaveRequest::factory()->forStudent($student)->create();

        $this->postJson("/api/v1/leave-requests/{$leaveRequest->id}/approve")->assertOk();

        foreach ([$studentUser, $teacherUser, $staffUser] as $recipient) {
            $this->assertSame(
                1,
                UserNotification::where('recipient_id', $recipient->id)->where('type', NotificationType::LEAVE_REQUEST_APPROVED)->count(),
                "Expected exactly one notification for user #{$recipient->id}",
            );
        }
        $this->assertSame(0, UserNotification::where('recipient_id', $bystander->id)->count());
    }

    public function test_rejecting_a_leave_request_notifies_only_the_requester(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::LEAVE_REQUESTS_REJECT]);
        [$student, $studentUser] = $this->studentWithUser();
        $leaveRequest = LeaveRequest::factory()->forStudent($student)->create();

        $this->postJson("/api/v1/leave-requests/{$leaveRequest->id}/reject", ['reason' => 'No reason given'])->assertOk();

        $notification = UserNotification::where('type', NotificationType::LEAVE_REQUEST_REJECTED)->sole();
        $this->assertSame($studentUser->id, $notification->recipient_id);
        $this->assertSame('No reason given', $notification->data['reason']);
    }

    public function test_a_student_must_pick_one_of_their_own_active_courses(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [, $user] = $this->studentWithUser();
        $someoneElses = Enrollment::factory()->create();
        $this->actingAsTenantUser($user);

        $payload = ['from_date' => now()->addDay()->toDateString(), 'to_date' => now()->addDay()->toDateString(), 'reason' => 'Sick'];

        $this->postJson('/api/v1/my-leave-requests', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['enrollment_id']);
        $this->postJson('/api/v1/my-leave-requests', [...$payload, 'enrollment_id' => $someoneElses->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['enrollment_id']);
    }

    public function test_the_enrollments_endpoint_lists_active_courses_newest_first_with_class_times(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$student, $user] = $this->studentWithUser();
        $class = SchoolClass::factory()->create();
        ClassSchedule::factory()->create(['class_id' => $class->id, 'day_of_week' => 3, 'start_time' => '14:00', 'end_time' => '16:00']);
        ClassSchedule::factory()->create(['class_id' => $class->id, 'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '10:00']);
        $older = Enrollment::factory()->forStudent($student)->create(['enrolled_at' => now()->subMonth()]);
        $newer = Enrollment::factory()->forStudent($student)->create(['class_id' => $class->id, 'enrolled_at' => now()]);
        $this->actingAsTenantUser($user);

        $response = $this->getJson('/api/v1/my-leave-requests/enrollments')->assertOk();

        $response->assertJsonPath('data.0.id', $newer->id);
        $response->assertJsonPath('data.1.id', $older->id);
        $response->assertJsonPath('data.0.schedules', [
            ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '10:00'],
            ['day_of_week' => 3, 'start_time' => '14:00', 'end_time' => '16:00'],
        ]);
    }

    public function test_approving_excuses_only_the_requested_course(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::LEAVE_REQUESTS_APPROVE]);
        [$student] = $this->studentWithUser();
        $monday = Carbon::parse('next monday')->toDateString();

        $enrollments = collect([0, 1])->map(function () use ($student) {
            $class = SchoolClass::factory()->create();
            ClassSchedule::factory()->create(['class_id' => $class->id, 'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '10:00']);

            return Enrollment::factory()->forStudent($student)->create(['class_id' => $class->id]);
        });

        $leave = LeaveRequest::factory()->forStudent($student)->create([
            'enrollment_id' => $enrollments[0]->id,
            'from_date' => $monday,
            'to_date' => $monday,
        ]);

        $this->actingAsTenantUser($admin);
        $this->postJson("/api/v1/leave-requests/{$leave->id}/approve")->assertOk();

        $this->assertSame(1, AttendanceRecord::where('enrollment_id', $enrollments[0]->id)->where('status', AttendanceStatus::EXCUSED)->count());
        $this->assertSame(0, AttendanceRecord::where('enrollment_id', $enrollments[1]->id)->count());
    }
}
