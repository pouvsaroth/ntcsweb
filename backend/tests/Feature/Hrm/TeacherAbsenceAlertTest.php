<?php

declare(strict_types=1);

namespace Tests\Feature\Hrm;

use App\Models\AttendanceRecord;
use App\Models\ClassSchedule;
use App\Models\Enrollment;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\StaffAttendance;
use App\Models\TeacherSubstitution;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Academic\AttendanceStatus;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** `teachers:alert-absent` — see TeacherAbsenceAlertService. */
class TeacherAbsenceAlertTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const TODAY = '2026-11-11'; // a Wednesday

    protected function setUp(): void
    {
        parent::setUp();
        // 10:00 in Phnom Penh.
        Carbon::setTestNow(Carbon::parse(self::TODAY.' 03:00:00', 'UTC'));
        // $this->admin can arrange substitutes: they get the alerts.
        $this->actingAsAdminWithPermissions([Permissions::TEACHER_SUBSTITUTES_CREATE]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A Wednesday class at $start–$end with one student and its own teacher. */
    private function wednesdayClass(string $start, string $end = '11:30'): array
    {
        $teacher = Staff::factory()->create(['first_name' => 'Dara', 'last_name' => 'T']);
        $class = SchoolClass::factory()->withTeacher($teacher)->create(['start_date' => '2026-10-01']);
        ClassSchedule::factory()->forClass($class)->onDay(ClassSchedule::WEDNESDAY)->at("{$start}:00", "{$end}:00")->create();
        $enrollment = Enrollment::factory()->forClass($class)->create();

        return [$class, $teacher, $enrollment];
    }

    private function checkedIn(Staff $staff, string $date): void
    {
        StaffAttendance::query()->create(['staff_id' => $staff->id, 'date' => $date, 'check_in_at' => "{$date} 07:30:00", 'status' => StaffAttendance::STATUS_PRESENT]);
    }

    private function alerts(?User $user = null): Collection
    {
        return UserNotification::query()->where('recipient_id', ($user ?? $this->admin)->id)
            ->whereIn('type', [NotificationType::TEACHER_ON_LEAVE_CLASS, NotificationType::TEACHER_NOT_CHECKED_IN_CLASS])->get();
    }

    private function alertNow(): void
    {
        $this->artisan('teachers:alert-absent')->assertSuccessful();
    }

    public function test_a_teacher_on_leave_is_reported_once_with_a_link_to_the_substitute_form(): void
    {
        [$class, $teacher] = $this->wednesdayClass('14:00', '15:00');
        LeaveRequest::query()->create(['staff_id' => $teacher->id, 'from_date' => self::TODAY, 'to_date' => self::TODAY, 'days' => 1, 'reason' => 'Sick', 'status' => LeaveRequest::STATUS_APPROVED]);

        $this->alertNow();
        $this->alertNow();

        $alert = $this->alerts()->sole();
        $this->assertSame(NotificationType::TEACHER_ON_LEAVE_CLASS, $alert->type);
        $this->assertSame('Dara T', $alert->data['teacher_name']);
        $this->assertSame('14:00', $alert->data['start_time']);
        $this->assertSame('11-11-2026', $alert->data['date']);
        $this->assertSame("/admin/teachers/substitutes?class_id={$class->id}&date=2026-11-11&absent_staff_id={$teacher->id}&start_time=14%3A00&end_time=15%3A00", $alert->link);
    }

    public function test_not_checked_in_ten_minutes_after_the_start(): void
    {
        [, $late] = $this->wednesdayClass('09:45'); // 10 minutes passed at 09:55
        [, $notYet] = $this->wednesdayClass('09:55'); // due 10:05
        [, $neverChecksIn] = $this->wednesdayClass('09:00');
        $this->checkedIn($late, '2026-11-10');
        $this->checkedIn($notYet, '2026-11-10');

        $this->alertNow();

        $alerts = $this->alerts();
        $this->assertCount(1, $alerts);
        $this->assertSame(NotificationType::TEACHER_NOT_CHECKED_IN_CLASS, $alerts->first()->type);
        $this->assertSame('09:45', $alerts->first()->data['start_time']);

        // Checked in today: nothing more.
        $this->checkedIn($notYet, self::TODAY);
        Carbon::setTestNow(Carbon::parse(self::TODAY.' 03:30:00', 'UTC'));
        $this->alertNow();
        $this->assertCount(1, $this->alerts());
    }

    public function test_nothing_when_a_substitute_is_set_attendance_is_saved_or_on_a_holiday(): void
    {
        [$covered, $a] = $this->wednesdayClass('09:00');
        [$taken, $b, $enrollment] = $this->wednesdayClass('09:00');
        [, $c] = $this->wednesdayClass('09:00');
        [, $ended] = $this->wednesdayClass('08:00', '09:00');
        foreach ([$a, $b, $c, $ended] as $staff) {
            $this->checkedIn($staff, '2026-11-10');
        }
        TeacherSubstitution::query()->create(['class_id' => $covered->id, 'absent_staff_id' => $a->id, 'date' => self::TODAY]);
        AttendanceRecord::factory()->forEnrollment($enrollment)->onDate(self::TODAY)->status(AttendanceStatus::PRESENT)->create(['class_id' => $taken->id]);
        Holiday::query()->create(['name' => 'Water Festival', 'start_date' => self::TODAY, 'end_date' => self::TODAY]);

        $this->alertNow();
        $this->assertCount(0, $this->alerts());

        Holiday::query()->delete();
        $this->alertNow();
        // Only $c: $a is covered, $b's class has attendance, $ended's session is over.
        $this->assertSame([$c->fullName()], $this->alerts()->pluck('data.teacher_name')->all());
    }

    public function test_school_admins_are_told_when_nobody_can_arrange_substitutes(): void
    {
        $this->admin->roles()->detach();
        $role = Role::factory()->forTenant($this->tenant)->create(['name' => 'School Admin', 'slug' => Role::SCHOOL_ADMIN]);
        $schoolAdmin = User::factory()->forTenant($this->tenant)->create();
        $schoolAdmin->attachRoles($role);
        [, $teacher] = $this->wednesdayClass('14:00', '15:00');
        LeaveRequest::query()->create(['staff_id' => $teacher->id, 'from_date' => self::TODAY, 'to_date' => self::TODAY, 'days' => 1, 'reason' => 'Sick', 'status' => LeaveRequest::STATUS_APPROVED]);

        $this->alertNow();

        $this->assertCount(1, $this->alerts($schoolAdmin));
    }
}
