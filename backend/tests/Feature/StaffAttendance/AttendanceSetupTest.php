<?php

declare(strict_types=1);

namespace Tests\Feature\StaffAttendance;

use App\Models\Shift;
use App\Models\Staff;
use App\Models\WorkSchedule;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * HRM > Attendance & Time's set-up — shifts, work schedules, holidays.
 */
class AttendanceSetupTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const ALL = [Permissions::STAFF_ATTENDANCE_VIEW, Permissions::STAFF_ATTENDANCE_MANAGE];

    public function test_a_shift_knows_its_working_minutes_including_overnight(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);

        $this->postJson('/api/v1/shifts', ['code' => 'DAY', 'name' => 'Day', 'start_time' => '08:00', 'end_time' => '17:00', 'break_minutes' => 60, 'late_grace_minutes' => 10])
            ->assertCreated()
            ->assertJsonPath('data.work_minutes', 480)
            ->assertJsonPath('data.overnight', false);

        $this->postJson('/api/v1/shifts', ['code' => 'NIGHT', 'name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00'])
            ->assertCreated()
            ->assertJsonPath('data.work_minutes', 480)
            ->assertJsonPath('data.overnight', true);

        $this->postJson('/api/v1/shifts', ['code' => 'DAY', 'name' => 'Again', 'start_time' => '25:00', 'end_time' => '08:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code', 'start_time']);
    }

    public function test_a_shift_saves_its_day_from_to_rows_and_takes_its_own_hours_from_the_first_day(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);

        $id = $this->postJson('/api/v1/shifts', ['code' => 'WEEK', 'name' => 'Week', 'days' => [
            ['day_of_week' => 6, 'start_time' => '07:00', 'end_time' => '11:00', 'break_minutes' => 0],
            ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '17:00', 'break_minutes' => 60],
        ]])
            ->assertCreated()
            ->assertJsonPath('data.start_time', '08:00')
            ->assertJsonPath('data.end_time', '17:00')
            ->assertJsonPath('data.break_minutes', 60)
            ->assertJsonPath('data.days.0.break_minutes', 60)
            ->assertJsonPath('data.days.1.break_minutes', 0)
            ->assertJsonPath('data.days.0.day_of_week', 1)
            ->assertJsonPath('data.days.1.day_of_week', 6)
            ->assertJsonPath('data.days.1.start_time', '07:00')
            ->json('data.id');

        // Editing replaces the rows.
        $this->putJson("/api/v1/shifts/{$id}", ['days' => [['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '18:00']]])
            ->assertOk()
            ->assertJsonCount(1, 'data.days')
            ->assertJsonPath('data.start_time', '09:00');

        $this->postJson('/api/v1/shifts', ['code' => 'DUP', 'name' => 'Dup', 'days' => [
            ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '17:00'],
            ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '12:00'],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['days.0.day_of_week']);
    }

    public function test_a_schedule_assigns_shifts_by_weekday_and_staff_follow_it_or_the_default(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $day = Shift::factory()->create();
        $half = Shift::factory()->create(['start_time' => '08:00', 'end_time' => '12:00', 'break_minutes' => 0]);
        [$teacher, $other] = Staff::factory()->count(2)->create();

        $default = $this->postJson('/api/v1/work-schedules', ['name' => 'Office', 'monday_shift_id' => $day->id, 'is_default' => true])->assertCreated()->json('data.id');
        $id = $this->postJson('/api/v1/work-schedules', [
            'name' => 'Teachers', 'monday_shift_id' => $day->id, 'saturday_shift_id' => $half->id, 'staff_ids' => [$teacher->id],
        ])->assertCreated()->assertJsonPath('data.staff_count', 1)->json('data.id');

        $schedule = WorkSchedule::forStaff($teacher->fresh());
        $this->assertSame($id, $schedule->id);
        $this->assertSame($half->id, $schedule->shiftIdOn(Carbon::parse('2026-10-10')));
        $this->assertNull($schedule->shiftIdOn(Carbon::parse('2026-10-11')));
        $this->assertSame($default, WorkSchedule::forStaff($other)->id);

        // Making the second one default clears the first.
        $this->putJson("/api/v1/work-schedules/{$id}", ['is_default' => true])->assertOk();
        $this->assertFalse(WorkSchedule::query()->find($default)->is_default);
    }

    public function test_a_shift_in_use_cannot_be_deleted(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $used = Shift::factory()->create();
        $unused = Shift::factory()->create();
        WorkSchedule::factory()->create(['wednesday_shift_id' => $used->id]);

        $this->deleteJson("/api/v1/shifts/{$used->id}")->assertUnprocessable();
        $this->deleteJson("/api/v1/shifts/{$unused->id}")->assertNoContent();
    }

    public function test_holidays_span_days_and_list_by_year(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);

        $this->postJson('/api/v1/holidays', ['name' => 'Khmer New Year', 'start_date' => '2027-04-14', 'end_date' => '2027-04-16'])
            ->assertCreated()
            ->assertJsonPath('data.days', 3);
        $this->postJson('/api/v1/holidays', ['name' => 'Independence Day', 'start_date' => '2026-11-09'])
            ->assertCreated()
            ->assertJsonPath('data.end_date', '2026-11-09');

        $this->getJson('/api/v1/holidays?year=2027')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_viewing_alone_cannot_change_the_set_up(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_ATTENDANCE_VIEW]);

        $this->getJson('/api/v1/shifts')->assertOk();
        $this->postJson('/api/v1/shifts', ['code' => 'X', 'name' => 'X', 'start_time' => '08:00', 'end_time' => '17:00'])->assertForbidden();
        $this->postJson('/api/v1/holidays', ['name' => 'X', 'start_date' => '2026-12-25'])->assertForbidden();
    }
}
