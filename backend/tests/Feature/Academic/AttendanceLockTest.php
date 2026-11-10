<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AttendanceRecord;
use App\Models\ClassSchedule;
use App\Models\Enrollment;
use App\Models\Holiday;
use App\Models\SchoolCalendarEvent;
use App\Models\SchoolClass;
use App\Support\Academic\AttendanceStatus;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * Today's attendance can't be saved while the class's previous class day
 * has none — see AttendanceService::missedClassDayBefore().
 */
class AttendanceLockTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const TODAY = '2026-11-11'; // a Wednesday

    private const MONDAY = '2026-11-09';

    private const LAST_WEDNESDAY = '2026-11-04';

    protected function setUp(): void
    {
        parent::setUp();
        // 10:00 in Phnom Penh, so "today" is the same date in UTC and in the school.
        Carbon::setTestNow(Carbon::parse(self::TODAY.' 03:00:00', 'UTC'));
        $this->actingAsAdminWithPermissions([Permissions::ATTENDANCE_CREATE, Permissions::ATTENDANCE_UPDATE]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A Monday + Wednesday class with one student. */
    private function monWedClass(): array
    {
        $class = SchoolClass::factory()->create(['start_date' => '2026-10-01']);
        ClassSchedule::factory()->forClass($class)->onDay(ClassSchedule::MONDAY)->at('18:00:00', '20:00:00')->create();
        ClassSchedule::factory()->forClass($class)->onDay(ClassSchedule::WEDNESDAY)->at('18:00:00', '20:00:00')->create();
        $enrollment = Enrollment::factory()->forClass($class)->create();

        return [$class, $enrollment];
    }

    private function take(SchoolClass $class, Enrollment $enrollment, string $date)
    {
        return $this->postJson("/api/v1/classes/{$class->id}/attendance", [
            'date' => $date,
            'entries' => [['enrollment_id' => $enrollment->id, 'status' => AttendanceStatus::PRESENT]],
        ]);
    }

    public function test_today_is_locked_until_the_previous_class_day_is_added(): void
    {
        [$class, $enrollment] = $this->monWedClass();

        $this->getJson("/api/v1/classes/{$class->id}/attendance?date=".self::TODAY)
            ->assertOk()
            ->assertJsonPath('meta.missed_date', self::MONDAY);

        $this->take($class, $enrollment, self::TODAY)
            ->assertUnprocessable()
            ->assertJsonPath('errors.date.0', 'Attendance for 09-11-2026 has not been added yet. Add that day first, then today.');
        $this->assertSame(0, AttendanceRecord::query()->whereDate('date', self::TODAY)->count());

        // Monday itself is a past day, so it isn't locked by the week before.
        $this->take($class, $enrollment, self::MONDAY)->assertSuccessful();

        $this->getJson("/api/v1/classes/{$class->id}/attendance?date=".self::TODAY)
            ->assertOk()
            ->assertJsonPath('meta.missed_date', null);
        $this->take($class, $enrollment, self::TODAY)->assertSuccessful();
    }

    public function test_a_holiday_is_skipped_when_finding_the_previous_class_day(): void
    {
        [$class, $enrollment] = $this->monWedClass();
        Holiday::query()->create(['name' => 'Water Festival', 'start_date' => self::MONDAY, 'end_date' => self::MONDAY]);

        $this->take($class, $enrollment, self::TODAY)
            ->assertUnprocessable()
            ->assertJsonPath('errors.date.0', 'Attendance for 04-11-2026 has not been added yet. Add that day first, then today.');

        $this->take($class, $enrollment, self::LAST_WEDNESDAY)->assertSuccessful();
        $this->take($class, $enrollment, self::TODAY)->assertSuccessful();
    }

    public function test_a_school_calendar_closing_day_is_skipped_too(): void
    {
        [$class, $enrollment] = $this->monWedClass();
        SchoolCalendarEvent::query()->create(['title' => 'School closed', 'type' => 'closing', 'start_date' => self::MONDAY, 'end_date' => self::MONDAY]);
        // Other calendar events (an exam, a meeting) are still class days.
        SchoolCalendarEvent::query()->create(['title' => 'Midterm', 'type' => 'exam', 'start_date' => self::LAST_WEDNESDAY, 'end_date' => self::LAST_WEDNESDAY]);

        $this->take($class, $enrollment, self::TODAY)
            ->assertUnprocessable()
            ->assertJsonPath('errors.date.0', 'Attendance for 04-11-2026 has not been added yet. Add that day first, then today.');

        $this->getJson("/api/v1/classes/{$class->id}/attendance?date=".self::MONDAY)
            ->assertOk()
            ->assertJsonPath('meta.day_off', 'School closed');
        $this->getJson("/api/v1/classes/{$class->id}/attendance?date=".self::TODAY)
            ->assertOk()
            ->assertJsonPath('meta.day_off', null);
    }

    public function test_a_class_that_started_today_or_has_no_schedule_is_not_locked(): void
    {
        $new = SchoolClass::factory()->create(['start_date' => self::TODAY]);
        ClassSchedule::factory()->forClass($new)->onDay(ClassSchedule::MONDAY)->create();
        $this->take($new, Enrollment::factory()->forClass($new)->create(), self::TODAY)->assertSuccessful();

        $unscheduled = SchoolClass::factory()->create(['start_date' => '2026-10-01']);
        $this->take($unscheduled, Enrollment::factory()->forClass($unscheduled)->create(), self::TODAY)->assertSuccessful();
    }
}
