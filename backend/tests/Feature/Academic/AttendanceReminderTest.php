<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AttendanceRecord;
use App\Models\ClassSchedule;
use App\Models\Enrollment;
use App\Models\Holiday;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Academic\AttendanceStatus;
use App\Support\Notifications\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** `attendance:remind-not-taken` — see AttendanceReminderService. */
class AttendanceReminderTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const TODAY = '2026-11-11'; // a Wednesday

    private User $schoolAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        // 20:00 in Phnom Penh.
        Carbon::setTestNow(Carbon::parse(self::TODAY.' 13:00:00', 'UTC'));
        $this->actingAsAdminWithPermissions([]);

        $role = Role::factory()->forTenant($this->tenant)->create(['name' => 'School Admin', 'slug' => Role::SCHOOL_ADMIN]);
        $this->schoolAdmin = User::factory()->forTenant($this->tenant)->create();
        $this->schoolAdmin->attachRoles($role);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A Wednesday class ending at $end, with one student and its own teacher. */
    private function wednesdayClass(string $end): array
    {
        $teacherUser = User::factory()->forTenant($this->tenant)->create();
        $class = SchoolClass::factory()->withTeacher(Staff::factory()->withUser($teacherUser)->create())->create(['start_date' => '2026-10-01']);
        ClassSchedule::factory()->forClass($class)->onDay(ClassSchedule::WEDNESDAY)->at('18:00:00', $end)->create();
        $enrollment = Enrollment::factory()->forClass($class)->create();

        return [$class, $teacherUser, $enrollment];
    }

    private function remindersFor(User $user): int
    {
        return UserNotification::query()->where('recipient_id', $user->id)->where('type', NotificationType::ATTENDANCE_NOT_TAKEN)->count();
    }

    public function test_the_class_teacher_and_admins_are_told_once_30_minutes_after_class_ends(): void
    {
        [$ended, $teacher] = $this->wednesdayClass('19:00:00');
        [, $laterTeacher] = $this->wednesdayClass('19:45:00'); // due 20:15, not yet

        $this->artisan('attendance:remind-not-taken')->assertSuccessful();
        $this->artisan('attendance:remind-not-taken')->assertSuccessful();

        $this->assertSame(1, $this->remindersFor($teacher));
        $this->assertSame(1, $this->remindersFor($this->schoolAdmin));
        $this->assertSame(0, $this->remindersFor($laterTeacher));
        $this->assertSame(0, $this->remindersFor($this->admin)); // not a school admin

        $data = UserNotification::query()->where('recipient_id', $teacher->id)->sole()->data;
        $this->assertSame($ended->name, $data['class_name']);
        $this->assertSame('11-11-2026', $data['date']);
        $this->assertSame('19:00', $data['end_time']);
    }

    public function test_no_reminder_when_attendance_was_added_or_on_a_holiday(): void
    {
        [$class, $teacher, $enrollment] = $this->wednesdayClass('19:00:00');
        AttendanceRecord::factory()->forEnrollment($enrollment)->onDate(self::TODAY)->status(AttendanceStatus::PRESENT)->create(['class_id' => $class->id]);

        [, $holidayTeacher] = $this->wednesdayClass('19:00:00');

        $this->artisan('attendance:remind-not-taken')->assertSuccessful();
        $this->assertSame(0, $this->remindersFor($teacher));
        $this->assertSame(1, $this->remindersFor($holidayTeacher));

        UserNotification::query()->delete();
        Holiday::query()->create(['name' => 'Water Festival', 'start_date' => self::TODAY, 'end_date' => self::TODAY]);
        $this->artisan('attendance:remind-not-taken')->assertSuccessful();
        $this->assertSame(0, UserNotification::query()->count());
    }
}
