<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AttendanceRecord;
use App\Models\ClassMakeupSession;
use App\Models\ClassSchedule;
use App\Models\Enrollment;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\TeacherSubstitution;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Academic\AttendanceStatus;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * A make-up session (Teachers > Substitute) counts as a class session: the
 * "attendance not taken" reminder, the attendance lock and Class sessions.
 */
class MakeupSessionCountsTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const TODAY = '2026-11-11'; // a Wednesday

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A Monday + Wednesday 18:00–19:00 class with one student and its teacher. */
    private function monWedClass(): array
    {
        $teacher = Staff::factory()->create(['first_name' => 'Dara', 'last_name' => 'T']);
        $class = SchoolClass::factory()->withTeacher($teacher)->create(['name' => '7A', 'start_date' => '2026-10-01']);
        ClassSchedule::factory()->forClass($class)->onDay(ClassSchedule::MONDAY)->at('18:00:00', '19:00:00')->create();
        ClassSchedule::factory()->forClass($class)->onDay(ClassSchedule::WEDNESDAY)->at('18:00:00', '19:00:00')->create();

        return [$class, $teacher, Enrollment::factory()->forClass($class)->create()];
    }

    private function notHeldWithMakeup(SchoolClass $class, Staff $absent, string $date, string $makeupDate, string $start, string $end, ?Staff $by = null): void
    {
        $row = TeacherSubstitution::query()->create(['class_id' => $class->id, 'absent_staff_id' => $absent->id, 'date' => $date]);
        ClassMakeupSession::query()->create(['class_id' => $class->id, 'teacher_substitution_id' => $row->id, 'date' => $makeupDate, 'start_time' => $start, 'end_time' => $end, 'staff_id' => ($by ?? $absent)->id]);
    }

    public function test_the_reminder_goes_out_after_a_make_up_session_and_tells_its_teacher(): void
    {
        // 10:00 in Phnom Penh.
        Carbon::setTestNow(Carbon::parse(self::TODAY.' 03:00:00', 'UTC'));
        $this->actingAsAdminWithPermissions([]);
        $role = Role::factory()->forTenant($this->tenant)->create(['name' => 'School Admin', 'slug' => Role::SCHOOL_ADMIN]);
        $schoolAdmin = User::factory()->forTenant($this->tenant)->create();
        $schoolAdmin->attachRoles($role);

        [$class, $dara] = $this->monWedClass();
        $sokUser = User::factory()->forTenant($this->tenant)->create();
        $sok = Staff::factory()->withUser($sokUser)->create();
        // Today's 18:00 not held; the class studies 08:00–09:00 instead, taught by Sok.
        $this->notHeldWithMakeup($class, $dara, self::TODAY, self::TODAY, '08:00', '09:00', $sok);

        $this->artisan('attendance:remind-not-taken')->assertSuccessful();

        $sent = UserNotification::query()->where('type', NotificationType::ATTENDANCE_NOT_TAKEN)->get();
        $this->assertSame(1, $sent->where('recipient_id', $sokUser->id)->count());
        $this->assertSame(1, $sent->where('recipient_id', $schoolAdmin->id)->count());
        $this->assertSame('09:00', $sent->first()->data['end_time']);
    }

    public function test_a_make_up_day_without_attendance_locks_the_next_class_day(): void
    {
        // 10:00 in Phnom Penh.
        Carbon::setTestNow(Carbon::parse(self::TODAY.' 03:00:00', 'UTC'));
        $this->actingAsAdminWithPermissions([Permissions::ATTENDANCE_CREATE, Permissions::ATTENDANCE_UPDATE]);
        [$class, $dara, $enrollment] = $this->monWedClass();
        // Monday 09-11 not held, made up on Tuesday 10-11.
        $this->notHeldWithMakeup($class, $dara, '2026-11-09', '2026-11-10', '18:00', '19:00');
        $take = fn (string $date) => $this->postJson("/api/v1/classes/{$class->id}/attendance", [
            'date' => $date,
            'entries' => [['enrollment_id' => $enrollment->id, 'status' => AttendanceStatus::PRESENT]],
        ]);

        $take(self::TODAY)->assertUnprocessable()->assertJsonPath('errors.date.0', fn ($m) => str_contains($m, '10-11-2026'));
        $take('2026-11-10')->assertSuccessful();
        $take(self::TODAY)->assertSuccessful();
    }

    public function test_class_sessions_lists_the_make_up_session(): void
    {
        // 20:00 in Phnom Penh, after the evening sessions.
        Carbon::setTestNow(Carbon::parse(self::TODAY.' 13:00:00', 'UTC'));
        $this->actingAsAdminWithPermissions([Permissions::TEACHERS_VIEW]);
        [$class, $dara, $enrollment] = $this->monWedClass();
        $this->notHeldWithMakeup($class, $dara, '2026-11-09', '2026-11-10', '18:00', '19:30');
        AttendanceRecord::factory()->forEnrollment($enrollment)->onDate('2026-11-10')->status(AttendanceStatus::PRESENT)->create(['class_id' => $class->id]);

        $data = $this->getJson('/api/v1/teachers/sessions?from=2026-11-09&to=2026-11-10')->assertOk()->json('data');

        $this->assertSame([
            ['2026-11-09', 'not_held', false],
            ['2026-11-10', 'taught', true],
        ], array_map(fn ($r) => [$r['date'], $r['status'], $r['makeup']], $data['rows']));
        $this->assertSame(90, $data['rows'][1]['minutes']);
        $this->assertSame(['planned' => 2, 'taught' => 1, 'not_held' => 1], array_intersect_key($data['summary'][0], array_flip(['planned', 'taught', 'not_held'])));
    }
}
