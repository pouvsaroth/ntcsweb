<?php

declare(strict_types=1);

namespace Tests\Feature\StaffAttendance;

use App\Models\AttendanceApproval;
use App\Models\AttendanceCorrection;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\StaffAttendance;
use App\Models\WorkSchedule;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * HRM > Attendance & Time: Attendance correction (through E-Approvals) and
 * Attendance approval (month sign-off, which locks it).
 */
class CorrectionAndSignOffTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const ALL = [
        Permissions::STAFF_ATTENDANCE_VIEW, Permissions::STAFF_ATTENDANCE_MANAGE, Permissions::STAFF_ATTENDANCE_APPROVE,
        Permissions::ATTENDANCE_CORRECTIONS_APPROVE, Permissions::ATTENDANCE_CORRECTIONS_REJECT,
    ];

    private function office(): void
    {
        $shift = Shift::factory()->create(['start_time' => '08:00', 'end_time' => '17:00', 'break_minutes' => 60, 'late_grace_minutes' => 10]);
        WorkSchedule::factory()->create(array_merge(
            ['is_default' => true],
            array_fill_keys(['monday_shift_id', 'tuesday_shift_id', 'wednesday_shift_id', 'thursday_shift_id', 'friday_shift_id'], $shift->id),
        ));
    }

    public function test_an_approved_correction_writes_the_fixed_times_onto_the_day(): void
    {
        $admin = $this->actingAsAdminWithPermissions(self::ALL);
        $this->office();
        $staff = Staff::factory()->create(['user_id' => $admin->id]);
        // Forgot to check out on Monday 2026-09-14.
        $this->postJson('/api/v1/staff-attendance', ['staff_id' => $staff->id, 'date' => '2026-09-14', 'check_in' => '08:00'])->assertCreated();

        $id = $this->postJson('/api/v1/my-attendance-corrections', ['date' => '2026-09-14', 'check_out' => '17:05', 'reason' => 'Forgot to check out'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.recorded.status', 'incomplete')
            ->json('data.id');
        $this->postJson('/api/v1/my-attendance-corrections', ['date' => '2026-09-14', 'check_out' => '17:10', 'reason' => 'Again'])->assertJsonValidationErrors('date');

        $this->getJson('/api/v1/attendance-corrections?approval_queue=1')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/attendance-corrections/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        $day = StaffAttendance::query()->where('staff_id', $staff->id)->sole();
        $this->assertSame('present', $day->status);
        $this->assertSame('correction', $day->check_out_source);
        $this->assertSame('17:05', $day->check_out_at->format('H:i'));
        $this->assertStringContainsString('Forgot to check out', (string) $day->note);
    }

    public function test_a_correction_needs_a_time(): void
    {
        $admin = $this->actingAsAdminWithPermissions([]);
        Staff::factory()->create(['user_id' => $admin->id]);

        $this->postJson('/api/v1/my-attendance-corrections', ['date' => '2026-09-14', 'reason' => 'x'])->assertJsonValidationErrors('check_in');
    }

    public function test_signing_off_a_month_locks_it_until_unlocked(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $this->office();
        $staff = Staff::factory()->create(['employee_code' => 'NTSS-0042']);
        $this->postJson('/api/v1/staff-attendance', ['staff_id' => $staff->id, 'date' => '2026-09-14', 'check_in' => '08:00', 'check_out' => '17:00'])->assertCreated();

        $this->postJson('/api/v1/attendance-approvals', ['month' => '2026-09', 'staff_ids' => [$staff->id]])->assertCreated()->assertJsonPath('data.signed_off', 1);
        $this->getJson('/api/v1/attendance-approvals?month=2026-09&search=NTSS-0042')->assertOk()->assertJsonPath('data.staff.0.approval.approved_by', $this->admin->name);

        // Locked: HR edits, deletes, imports and corrections are refused.
        $this->postJson('/api/v1/staff-attendance', ['staff_id' => $staff->id, 'date' => '2026-09-15', 'check_in' => '08:00'])->assertUnprocessable();
        $this->deleteJson('/api/v1/staff-attendance/'.StaffAttendance::query()->value('id'))->assertUnprocessable();
        $this->postJson('/api/v1/attendance-corrections', ['staff_id' => $staff->id, 'date' => '2026-09-15', 'check_in' => '08:00', 'reason' => 'x'])->assertUnprocessable();
        $this->post('/api/v1/staff-attendance/import', ['file' => UploadedFile::fake()->createWithContent('p.csv', "employee_code,date,time\nNTSS-0042,2026-09-16,08:00\n")], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonCount(1, 'data.errors');
        $this->assertSame(1, StaffAttendance::query()->count());

        // Unlocked: editable again.
        $this->deleteJson('/api/v1/attendance-approvals/'.AttendanceApproval::query()->value('id'))->assertNoContent();
        $this->postJson('/api/v1/staff-attendance', ['staff_id' => $staff->id, 'date' => '2026-09-15', 'check_in' => '08:00'])->assertCreated();
    }

    public function test_signing_off_needs_its_own_permission_and_not_a_future_month(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_ATTENDANCE_VIEW, Permissions::STAFF_ATTENDANCE_MANAGE]);
        $staff = Staff::factory()->create();

        $this->postJson('/api/v1/attendance-approvals', ['month' => '2026-09', 'staff_ids' => [$staff->id]])->assertForbidden();
        $this->assertSame(0, AttendanceApproval::query()->count());
    }

    public function test_a_future_month_cannot_be_signed_off(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $staff = Staff::factory()->create();

        $this->postJson('/api/v1/attendance-approvals', ['month' => now()->addMonths(2)->format('Y-m'), 'staff_ids' => [$staff->id]])->assertJsonValidationErrors('month');
    }

    public function test_deciding_a_correction_needs_the_correction_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_ATTENDANCE_VIEW]);
        $correction = AttendanceCorrection::query()->create([
            'staff_id' => Staff::factory()->create()->id, 'date' => '2026-10-01', 'check_in' => '08:00', 'reason' => 'x', 'requested_by' => $this->admin->id,
        ]);

        $this->postJson("/api/v1/attendance-corrections/{$correction->id}/approve")->assertForbidden();
    }
}
