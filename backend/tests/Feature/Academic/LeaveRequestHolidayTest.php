<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AttendanceRecord;
use App\Models\ClassSchedule;
use App\Models\Enrollment;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LookupCategory;
use App\Models\LookupValue;
use App\Models\SchoolClass;
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
 * Student permissions and holidays — none is needed on a day off, and a
 * holiday added later cancels the ones it covers (LeaveRequestService).
 */
class LeaveRequestHolidayTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const ADMIN = [Permissions::LEAVE_REQUESTS_APPROVE, Permissions::STAFF_ATTENDANCE_VIEW, Permissions::STAFF_ATTENDANCE_MANAGE];

    public function test_a_permission_wholly_on_a_holiday_is_refused(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$student, $user] = $this->studentWithUser();
        $enrollment = Enrollment::factory()->forStudent($student)->create();
        $monday = Carbon::now()->next(Carbon::MONDAY)->toDateString();
        Holiday::query()->create(['name' => 'Visak Bochea', 'start_date' => $monday, 'end_date' => $monday]);
        $this->actingAsTenantUser($user);

        $this->postJson('/api/v1/my-leave-requests', ['enrollment_id' => $enrollment->id, 'from_date' => $monday, 'to_date' => $monday, 'reason' => 'Trip'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('from_date');
        $this->assertSame(0, LeaveRequest::query()->where('student_id', $student->id)->count());
    }

    public function test_a_permission_partly_on_a_holiday_goes_through_and_names_the_skipped_days(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$student, $user] = $this->studentWithUser();
        $enrollment = Enrollment::factory()->forStudent($student)->create();
        $monday = Carbon::now()->next(Carbon::MONDAY);
        Holiday::query()->create(['name' => 'Visak Bochea', 'start_date' => $monday->toDateString(), 'end_date' => $monday->toDateString()]);
        $this->actingAsTenantUser($user);

        $this->postJson('/api/v1/my-leave-requests', [
            'enrollment_id' => $enrollment->id,
            'from_date' => $monday->toDateString(),
            'to_date' => $monday->copy()->addDay()->toDateString(),
            'reason' => 'Trip',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_PENDING)
            ->assertJsonPath('data.holiday_dates', [$monday->toDateString()]);
    }

    public function test_approving_skips_holiday_days(): void
    {
        $this->actingAsAdminWithPermissions(self::ADMIN);
        [$student] = $this->studentWithUser();
        [$enrollment, $first, $second] = $this->mondayClass($student);
        Holiday::query()->create(['name' => 'Visak Bochea', 'start_date' => $first, 'end_date' => $first]);
        $leave = LeaveRequest::factory()->forStudent($student)->create(['from_date' => $first, 'to_date' => $second]);

        $this->postJson("/api/v1/leave-requests/{$leave->id}/approve")->assertOk();

        $dates = AttendanceRecord::query()->where('enrollment_id', $enrollment->id)->get()->map(fn ($r) => $r->date->toDateString())->all();
        $this->assertSame([$second], $dates);
    }

    public function test_a_holiday_added_over_an_approved_permission_cancels_it_and_removes_its_excused_mark(): void
    {
        $this->actingAsAdminWithPermissions(self::ADMIN);
        $this->seedHolidayTypes();
        [$student, $user] = $this->studentWithUser();
        [$enrollment, $first] = $this->mondayClass($student);
        $leave = LeaveRequest::factory()->forStudent($student)->create(['from_date' => $first, 'to_date' => $first]);
        $this->postJson("/api/v1/leave-requests/{$leave->id}/approve")->assertOk();
        $this->assertSame(1, AttendanceRecord::query()->where('enrollment_id', $enrollment->id)->count());

        $this->postJson('/api/v1/holidays', ['name' => 'School Sports Day', 'holiday_type' => 'SCHOOL_HOLIDAY', 'start_date' => $first])
            ->assertCreated()
            ->assertJsonPath('data.cancelled_permissions', 1);

        $leave->refresh();
        $this->assertSame(LeaveRequest::STATUS_CANCELLED, $leave->status);
        $this->assertStringContainsString('School Sports Day', (string) $leave->decision_reason);
        $this->assertSame(0, AttendanceRecord::query()->where('enrollment_id', $enrollment->id)->count());
        $this->assertTrue(UserNotification::query()->where('recipient_id', $user->id)->where('type', NotificationType::LEAVE_REQUEST_CANCELLED)->exists());
    }

    public function test_a_holiday_over_part_of_an_approved_permission_keeps_it_and_only_clears_that_day(): void
    {
        $this->actingAsAdminWithPermissions(self::ADMIN);
        $this->seedHolidayTypes();
        [$student] = $this->studentWithUser();
        [$enrollment, $first, $second] = $this->mondayClass($student);
        $leave = LeaveRequest::factory()->forStudent($student)->create(['from_date' => $first, 'to_date' => $second]);
        $this->postJson("/api/v1/leave-requests/{$leave->id}/approve")->assertOk();

        $this->postJson('/api/v1/holidays', ['name' => 'Extra day off', 'holiday_type' => 'ADDITIONAL_HOLIDAY', 'start_date' => $first])
            ->assertCreated()
            ->assertJsonPath('data.cancelled_permissions', 0);

        $this->assertSame(LeaveRequest::STATUS_APPROVED, $leave->fresh()->status);
        $dates = AttendanceRecord::query()->where('enrollment_id', $enrollment->id)->get()->map(fn ($r) => $r->date->toDateString())->all();
        $this->assertSame([$second], $dates);
    }

    public function test_a_waiting_permission_a_holiday_covers_is_cancelled_too_but_a_teachers_own_mark_stays(): void
    {
        $this->actingAsAdminWithPermissions(self::ADMIN);
        $this->seedHolidayTypes();
        [$student] = $this->studentWithUser();
        [$enrollment, $first] = $this->mondayClass($student);
        $waiting = LeaveRequest::factory()->forStudent($student)->create(['from_date' => $first, 'to_date' => $first]);
        // Marked by a teacher, not by any permission — must survive.
        AttendanceRecord::factory()->create([
            'enrollment_id' => $enrollment->id, 'class_id' => $enrollment->class_id, 'student_id' => $student->id,
            'date' => $first, 'status' => AttendanceStatus::EXCUSED, 'remarks' => 'Phoned in sick',
        ]);

        $this->postJson('/api/v1/holidays', ['name' => 'Public day', 'holiday_type' => 'PUBLIC_HOLIDAY', 'start_date' => $first])->assertCreated();

        $this->assertSame(LeaveRequest::STATUS_CANCELLED, $waiting->fresh()->status);
        $this->assertSame(1, AttendanceRecord::query()->where('enrollment_id', $enrollment->id)->count());
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

    private function seedHolidayTypes(): void
    {
        $category = LookupCategory::query()->create(['code' => 'HOLIDAY_TYPE', 'name' => 'Holiday Type']);
        foreach (['PUBLIC_HOLIDAY', 'SCHOOL_HOLIDAY', 'ADDITIONAL_HOLIDAY'] as $i => $code) {
            LookupValue::query()->create(['lookup_category_id' => $category->id, 'code' => $code, 'sort_order' => $i]);
        }
    }
}
