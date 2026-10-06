<?php

declare(strict_types=1);

namespace Tests\Feature\StaffAttendance;

use App\Models\OvertimeRequest;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\UserNotification;
use App\Models\WorkSchedule;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * HRM > Attendance & Time: Late / Early leave / Absence / Overtime reports,
 * and overtime requests through E-Approvals.
 */
class OvertimeAndReportsTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function office(): void
    {
        $shift = Shift::factory()->create(['start_time' => '08:00', 'end_time' => '17:00', 'break_minutes' => 60, 'late_grace_minutes' => 10]);
        WorkSchedule::factory()->create(array_merge(
            ['is_default' => true],
            array_fill_keys(['monday_shift_id', 'tuesday_shift_id', 'wednesday_shift_id', 'thursday_shift_id', 'friday_shift_id'], $shift->id),
        ));
    }

    public function test_the_reports_list_each_occurrence_with_a_total_per_person(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_ATTENDANCE_VIEW, Permissions::STAFF_ATTENDANCE_MANAGE]);
        $this->office();
        $staff = Staff::factory()->create(['hire_date' => '2026-01-01']);
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00', 'Asia/Phnom_Penh'));

        // Mon 12: 30 min late. Tue 13: left 45 min early. Wed 14: stayed 2h. Thu 15 / Fri 16: nothing (absent).
        $this->postJson('/api/v1/staff-attendance', ['staff_id' => $staff->id, 'date' => '2026-10-12', 'check_in' => '08:30', 'check_out' => '17:00'])->assertCreated();
        $this->postJson('/api/v1/staff-attendance', ['staff_id' => $staff->id, 'date' => '2026-10-13', 'check_in' => '08:00', 'check_out' => '16:15'])->assertCreated();
        $this->postJson('/api/v1/staff-attendance', ['staff_id' => $staff->id, 'date' => '2026-10-14', 'check_in' => '08:00', 'check_out' => '19:00'])->assertCreated();

        $query = fn (string $type) => $this->getJson("/api/v1/staff-attendance/report?type={$type}&from=2026-10-12&to=2026-10-16&search={$staff->employee_code}")->assertOk();

        $query('late')->assertJsonPath('data.total', 1)->assertJsonPath('data.items.0.minutes', 30)->assertJsonPath('data.summary.0.minutes', 30);
        $query('early_leave')->assertJsonPath('data.total', 1)->assertJsonPath('data.items.0.date', '2026-10-13')->assertJsonPath('data.items.0.minutes', 45);
        $query('overtime')->assertJsonPath('data.total', 1)->assertJsonPath('data.items.0.minutes', 120);
        $query('absence')->assertJsonPath('data.total', 2)->assertJsonPath('data.summary.0.count', 2);
    }

    public function test_a_staff_member_claims_overtime_and_it_is_decided_in_e_approvals(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::OVERTIME_REQUESTS_APPROVE, Permissions::STAFF_ATTENDANCE_VIEW]);
        Staff::factory()->create(['user_id' => $admin->id]);

        $id = $this->postJson('/api/v1/my-overtime-requests', ['date' => now()->subDay()->toDateString(), 'minutes' => 90, 'reason' => 'Parents meeting'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->json('data.id');
        $this->postJson('/api/v1/my-overtime-requests', ['date' => now()->subDay()->toDateString(), 'minutes' => 30, 'reason' => 'Again'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
        $this->postJson('/api/v1/my-overtime-requests', ['date' => now()->addDay()->toDateString(), 'minutes' => 30, 'reason' => 'Future'])
            ->assertJsonValidationErrors('date');

        $this->getJson('/api/v1/overtime-requests?approval_queue=1')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(1, UserNotification::query()->where('recipient_id', $admin->id)->where('type', NotificationType::OVERTIME_REQUEST_SUBMITTED)->count());

        $this->postJson("/api/v1/overtime-requests/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame(1, UserNotification::query()->where('recipient_id', $admin->id)->where('type', NotificationType::OVERTIME_REQUEST_APPROVED)->count());
        $this->getJson('/api/v1/my-overtime-requests')->assertOk()->assertJsonPath('data.0.status', 'approved');
    }

    public function test_deciding_needs_the_overtime_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_ATTENDANCE_VIEW]);
        $request = OvertimeRequest::query()->create([
            'staff_id' => Staff::factory()->create()->id, 'date' => '2026-10-01', 'minutes' => 60, 'reason' => 'x', 'requested_by' => $this->admin->id,
        ]);

        $this->postJson("/api/v1/overtime-requests/{$request->id}/approve")->assertForbidden();
        $this->postJson("/api/v1/overtime-requests/{$request->id}/reject", ['reason' => 'no'])->assertForbidden();
    }
}
