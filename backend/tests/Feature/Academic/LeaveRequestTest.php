<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AttendanceRecord;
use App\Models\ClassSchedule;
use App\Models\Enrollment;
use App\Models\LeaveRequest;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Support\Academic\AttendanceStatus;
use App\Support\Authorization\Permissions;
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
        $student = Student::factory()->forTenant($this->tenant)->create(['user_id' => $user->id]);

        return [$student, $user];
    }

    public function test_a_student_can_submit_a_leave_request_with_an_attachment_and_it_starts_pending(): void
    {
        Storage::fake('public');
        $this->actingAsAdminWithPermissions([]);
        [$student, $user] = $this->studentWithUser();
        $this->actingAsTenantUser($user);

        $response = $this->post('/api/v1/my-leave-requests', [
            'from_date' => now()->addDay()->toDateString(),
            'to_date' => now()->addDays(2)->toDateString(),
            'reason' => 'Family event out of town',
            'attachments' => [UploadedFile::fake()->image('doctor-note.jpg')],
        ], ['Accept' => 'application/json']);

        $response->assertCreated();
        $response->assertJsonPath('data.status', LeaveRequest::STATUS_PENDING);
        $response->assertJsonCount(1, 'data.attachments');
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

        $class = SchoolClass::factory()->forTenant($this->tenant)->create();
        ClassSchedule::factory()->forTenant($this->tenant)->forClass($class)->onDay(ClassSchedule::MONDAY)->create();
        $enrollment = Enrollment::factory()->forTenant($this->tenant)->forClass($class)->forStudent($student)->create();

        $firstMonday = Carbon::now()->next(Carbon::MONDAY);
        $secondMonday = $firstMonday->copy()->addWeek();

        $leaveRequest = LeaveRequest::factory()->forTenant($this->tenant)->forStudent($student)->create([
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

    public function test_approving_a_leave_request_requires_the_approve_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$student] = $this->studentWithUser();
        $leaveRequest = LeaveRequest::factory()->forTenant($this->tenant)->forStudent($student)->create();

        $this->postJson("/api/v1/leave-requests/{$leaveRequest->id}/approve")->assertForbidden();
    }

    public function test_approving_an_already_decided_leave_request_fails(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::LEAVE_REQUESTS_APPROVE]);
        [$student] = $this->studentWithUser();
        $leaveRequest = LeaveRequest::factory()->forTenant($this->tenant)->forStudent($student)->create([
            'status' => LeaveRequest::STATUS_APPROVED,
        ]);

        $this->postJson("/api/v1/leave-requests/{$leaveRequest->id}/approve")->assertUnprocessable();
    }

    public function test_rejecting_a_leave_request_records_a_reason_and_leaves_attendance_untouched(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::LEAVE_REQUESTS_REJECT]);
        [$student] = $this->studentWithUser();
        $class = SchoolClass::factory()->forTenant($this->tenant)->create();
        ClassSchedule::factory()->forTenant($this->tenant)->forClass($class)->onDay(ClassSchedule::MONDAY)->create();
        Enrollment::factory()->forTenant($this->tenant)->forClass($class)->forStudent($student)->create();

        $leaveRequest = LeaveRequest::factory()->forTenant($this->tenant)->forStudent($student)->create([
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

        LeaveRequest::factory()->forTenant($this->tenant)->forStudent($student)->create(['reason' => 'Mine']);
        LeaveRequest::factory()->forTenant($this->tenant)->forStudent($otherStudent)->create(['reason' => 'Not mine']);

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
}
