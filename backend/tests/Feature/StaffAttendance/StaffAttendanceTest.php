<?php

declare(strict_types=1);

namespace Tests\Feature\StaffAttendance;

use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\StaffAttendance;
use App\Models\WorkSchedule;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * HRM > Attendance & Time: check-in / check-out, HR entries, imports, and the
 * month sheet — see StaffAttendanceService. Times are local (Asia/Phnom_Penh, UTC+7).
 */
class StaffAttendanceTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const ALL = [Permissions::STAFF_ATTENDANCE_VIEW, Permissions::STAFF_ATTENDANCE_MANAGE];

    private Shift $shift;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** 08:00–17:00, 60 min break, 10 min late allowance — Monday to Friday, as the default schedule. */
    private function office(): void
    {
        $this->shift = Shift::factory()->create(['start_time' => '08:00', 'end_time' => '17:00', 'break_minutes' => 60, 'late_grace_minutes' => 10]);
        WorkSchedule::factory()->create(array_merge(
            ['is_default' => true],
            array_fill_keys(['monday_shift_id', 'tuesday_shift_id', 'wednesday_shift_id', 'thursday_shift_id', 'friday_shift_id'], $this->shift->id),
        ));
    }

    /** A local Phnom Penh time, as "now". */
    private function at(string $local): void
    {
        Carbon::setTestNow(Carbon::parse($local, 'Asia/Phnom_Penh'));
    }

    public function test_checking_in_late_and_out_early_is_worked_out_from_the_shift(): void
    {
        $admin = $this->actingAsAdminWithPermissions([]);
        $staff = Staff::factory()->create(['user_id' => $admin->id]);
        $this->office();

        // Monday 2026-10-12, 08:25 local: 25 min after start, past the 10-min allowance.
        $this->at('2026-10-12 08:25');
        $this->postJson('/api/v1/my-staff-attendance/check-in', ['latitude' => 11.55, 'longitude' => 104.92])
            ->assertOk()
            ->assertJsonPath('data.status', 'incomplete')
            ->assertJsonPath('data.late_minutes', 25)
            ->assertJsonPath('data.check_in_location.lat', 11.55);
        $this->postJson('/api/v1/my-staff-attendance/check-in')->assertUnprocessable();

        $this->at('2026-10-12 16:30');
        $this->postJson('/api/v1/my-staff-attendance/check-out')
            ->assertOk()
            ->assertJsonPath('data.status', 'late')
            ->assertJsonPath('data.early_leave_minutes', 30)
            ->assertJsonPath('data.worked_minutes', 425);

        $this->assertSame(1, StaffAttendance::query()->where('staff_id', $staff->id)->count());
    }

    public function test_lateness_uses_that_weekdays_own_hours_when_the_shift_has_day_rows(): void
    {
        $admin = $this->actingAsAdminWithPermissions([]);
        Staff::factory()->create(['user_id' => $admin->id]);
        $this->office();
        // Saturday is 07:00–11:00; every other day keeps the shift's 08:00–17:00.
        $this->shift->days()->create(['day_of_week' => 6, 'start_time' => '07:00', 'end_time' => '11:00']);
        WorkSchedule::query()->update(['saturday_shift_id' => $this->shift->id]);

        // Saturday 2026-10-17, 07:20 local: 20 min late against Saturday's 07:00.
        $this->at('2026-10-17 07:20');
        $this->postJson('/api/v1/my-staff-attendance/check-in')
            ->assertOk()
            ->assertJsonPath('data.late_minutes', 20);
        $this->getJson('/api/v1/my-staff-attendance/today')
            ->assertOk()
            ->assertJsonPath('data.shift.start_time', '07:00')
            ->assertJsonPath('data.shift.end_time', '11:00');
    }

    public function test_a_staff_member_sees_their_own_month(): void
    {
        $admin = $this->actingAsAdminWithPermissions([]);
        Staff::factory()->create(['user_id' => $admin->id]);
        $this->office();
        $this->at('2026-10-12 09:00');

        $this->getJson('/api/v1/my-staff-attendance?month=2026-10')
            ->assertOk()
            ->assertJsonPath('data.month', '2026-10')
            ->assertJsonCount(31, 'data.days');
    }

    public function test_within_the_allowance_is_on_time_and_staying_late_is_overtime(): void
    {
        $admin = $this->actingAsAdminWithPermissions([]);
        Staff::factory()->create(['user_id' => $admin->id]);
        $this->office();

        $this->at('2026-10-13 08:09');
        $this->postJson('/api/v1/my-staff-attendance/check-in')->assertOk()->assertJsonPath('data.late_minutes', 0);
        $this->at('2026-10-13 18:15');
        $this->postJson('/api/v1/my-staff-attendance/check-out')
            ->assertOk()
            ->assertJsonPath('data.status', 'present')
            ->assertJsonPath('data.overtime_minutes', 75);
    }

    public function test_someone_without_a_staff_record_cannot_check_in(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->postJson('/api/v1/my-staff-attendance/check-in')->assertForbidden();
    }

    public function test_hr_records_a_day_and_an_overnight_check_out(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $night = Shift::factory()->create(['start_time' => '22:00', 'end_time' => '06:00', 'break_minutes' => 0]);
        $staff = Staff::factory()->create(['work_schedule_id' => WorkSchedule::factory()->create(['tuesday_shift_id' => $night->id])->id]);

        $this->postJson('/api/v1/staff-attendance', ['staff_id' => $staff->id, 'date' => '2026-10-13', 'check_in' => '22:00', 'check_out' => '06:00', 'note' => 'Fingerprint broken'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'present')
            ->assertJsonPath('data.worked_minutes', 480)
            ->assertJsonPath('data.check_in_source', 'manual');
    }

    public function test_a_fingerprint_export_imports_first_and_last_punch_per_day(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $this->office();
        $staff = Staff::factory()->create(['employee_code' => 'NTSS-0007']);
        $csv = "Employee Code,Date,Time\nNTSS-0007,2026-10-14,07:55\nNTSS-0007,2026-10-14,12:01\nNTSS-0007,2026-10-14,17:05\nNOBODY,2026-10-14,08:00\n";

        $this->post('/api/v1/staff-attendance/import', ['file' => UploadedFile::fake()->createWithContent('punches.csv', $csv)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.days', 1)
            ->assertJsonCount(1, 'data.errors');

        $row = StaffAttendance::query()->where('staff_id', $staff->id)->sole();
        $this->assertSame('present', $row->status);
        $this->assertSame('import', $row->check_in_source);
        $this->assertSame('07:55', $row->check_in_at->timezone('Asia/Phnom_Penh')->format('H:i'));
        $this->assertSame('17:05', $row->check_out_at->timezone('Asia/Phnom_Penh')->format('H:i'));
    }

    public function test_the_month_sheet_fills_in_absent_off_holiday_and_leave(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $this->office();
        $staff = Staff::factory()->create(['hire_date' => '2026-01-01']);
        Holiday::query()->create(['name' => 'Holiday', 'start_date' => '2026-10-15', 'end_date' => '2026-10-15']);
        LeaveRequest::query()->create(['staff_id' => $staff->id, 'from_date' => '2026-10-16', 'to_date' => '2026-10-16', 'reason' => 'Sick', 'status' => LeaveRequest::STATUS_APPROVED]);
        $this->postJson('/api/v1/staff-attendance', ['staff_id' => $staff->id, 'date' => '2026-10-12', 'check_in' => '08:00', 'check_out' => '17:00'])->assertCreated();
        $this->at('2026-10-20 12:00');

        $days = collect($this->getJson("/api/v1/staff-attendance/sheet?month=2026-10&staff_id={$staff->id}")->assertOk()->json('data.staff.0.days'))->keyBy('date');

        $this->assertSame('present', $days['2026-10-12']['status']);
        $this->assertSame('absent', $days['2026-10-14']['status']);
        $this->assertSame('holiday', $days['2026-10-15']['status']);
        $this->assertSame('leave', $days['2026-10-16']['status']);
        $this->assertSame('off', $days['2026-10-17']['status']);
        $this->assertSame('upcoming', $days['2026-10-21']['status']);
    }

    public function test_viewing_needs_the_staff_attendance_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_ATTENDANCE_VIEW]);

        $this->getJson('/api/v1/staff-attendance/sheet')->assertOk();
        $this->postJson('/api/v1/staff-attendance', ['staff_id' => Staff::factory()->create()->id, 'date' => '2026-10-12', 'check_in' => '08:00'])->assertForbidden();
    }
}
