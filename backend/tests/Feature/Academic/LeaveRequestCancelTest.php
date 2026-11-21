<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AttendanceRecord;
use App\Models\ClassSchedule;
use App\Models\Enrollment;
use App\Models\LeaveRequest;
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
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * Staff take back an approved student permission (LeaveRequestService::cancel())
 * — e.g. the student came back early and asks again for the days really missed.
 */
class LeaveRequestCancelTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const ADMIN = [Permissions::LEAVE_REQUESTS_APPROVE, Permissions::LEAVE_REQUESTS_REJECT];

    public function test_cancelling_an_approved_permission_removes_its_excused_marks_and_tells_the_student(): void
    {
        $this->actingAsAdminWithPermissions(self::ADMIN);
        [$student, $user] = $this->studentWithUser();
        [$enrollment, $first, $second] = $this->mondayClass($student);
        $leave = LeaveRequest::factory()->forStudent($student)->create(['from_date' => $first, 'to_date' => $second]);
        $this->postJson("/api/v1/leave-requests/{$leave->id}/approve")->assertOk();
        $this->assertSame(2, AttendanceRecord::query()->where('enrollment_id', $enrollment->id)->count());

        $this->postJson("/api/v1/leave-requests/{$leave->id}/cancel", ['reason' => 'Came back after 2 days'])
            ->assertOk()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_CANCELLED);

        $leave->refresh();
        $this->assertSame('Came back after 2 days', $leave->decision_reason);
        $this->assertSame(0, AttendanceRecord::query()->where('enrollment_id', $enrollment->id)->count());
        $this->assertTrue(UserNotification::query()->where('recipient_id', $user->id)->where('type', NotificationType::LEAVE_REQUEST_CANCELLED_BY_SCHOOL)->exists());
    }

    public function test_a_day_the_teacher_changed_stays(): void
    {
        $this->actingAsAdminWithPermissions(self::ADMIN);
        [$student] = $this->studentWithUser();
        [$enrollment, $first, $second] = $this->mondayClass($student);
        $leave = LeaveRequest::factory()->forStudent($student)->create(['from_date' => $first, 'to_date' => $second]);
        $this->postJson("/api/v1/leave-requests/{$leave->id}/approve")->assertOk();
        // The teacher marked the student Present on the second Monday.
        AttendanceRecord::query()->where('enrollment_id', $enrollment->id)->whereDate('date', $second)->update(['status' => AttendanceStatus::PRESENT]);

        $this->postJson("/api/v1/leave-requests/{$leave->id}/cancel", ['reason' => 'Came back'])->assertOk();

        $left = AttendanceRecord::query()->where('enrollment_id', $enrollment->id)->get();
        $this->assertCount(1, $left);
        $this->assertSame($second, $left->first()->date->toDateString());
    }

    public function test_only_an_approved_student_permission_can_be_cancelled_and_a_reason_is_needed(): void
    {
        $this->actingAsAdminWithPermissions(self::ADMIN);
        [$student] = $this->studentWithUser();
        $waiting = LeaveRequest::factory()->forStudent($student)->create();

        $this->postJson("/api/v1/leave-requests/{$waiting->id}/cancel", ['reason' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson("/api/v1/leave-requests/{$waiting->id}/cancel", [])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');

        $staffLeave = LeaveRequest::factory()->create(['student_id' => null, 'staff_id' => Staff::factory()->create()->id, 'status' => LeaveRequest::STATUS_APPROVED]);
        $this->postJson("/api/v1/leave-requests/{$staffLeave->id}/cancel", ['reason' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame(LeaveRequest::STATUS_APPROVED, $staffLeave->fresh()->status);
    }

    public function test_cancelling_needs_the_reject_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::LEAVE_REQUESTS_APPROVE]);
        [$student] = $this->studentWithUser();
        $leave = LeaveRequest::factory()->forStudent($student)->create(['status' => LeaveRequest::STATUS_APPROVED]);

        $this->postJson("/api/v1/leave-requests/{$leave->id}/cancel", ['reason' => 'x'])->assertForbidden();
    }

    /** @return array{0: Student, 1: User} */
    private function studentWithUser(): array
    {
        $user = User::factory()->forTenant($this->tenant)->create();

        return [Student::factory()->create(['user_id' => $user->id]), $user];
    }

    /** @return array{0: Enrollment, 1: string, 2: string} the enrollment and the next two Mondays */
    private function mondayClass(Student $student): array
    {
        $class = SchoolClass::factory()->create();
        ClassSchedule::factory()->forClass($class)->onDay(ClassSchedule::MONDAY)->create();
        $enrollment = Enrollment::factory()->forClass($class)->forStudent($student)->create();
        $first = Carbon::now()->next(Carbon::MONDAY);

        return [$enrollment, $first->toDateString(), $first->copy()->addWeek()->toDateString()];
    }
}
