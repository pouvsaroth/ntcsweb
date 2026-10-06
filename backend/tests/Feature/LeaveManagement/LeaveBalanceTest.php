<?php

declare(strict_types=1);

namespace Tests\Feature\LeaveManagement;

use App\Models\LeaveBalanceEntry;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Staff;
use App\Services\Leave\LeaveBalanceService;
use App\Support\Authorization\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * HRM > Leave Management: balances (entitlement + carried forward +
 * adjustments − used − pending), HR's adjustments, and year-end carry
 * forward. "Today" is 5 October 2026.
 */
class LeaveBalanceTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const ALL = [Permissions::LEAVE_MANAGEMENT_VIEW, Permissions::LEAVE_MANAGEMENT_MANAGE];

    private Staff $staff;

    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 09:00:00');

        $this->staff = Staff::factory()->create(['hire_date' => '2020-01-01']);
        $this->annual = LeaveType::factory()->create(['name' => 'Annual leave']);
        LeavePolicy::factory()->create([
            'leave_type_id' => $this->annual->id, 'days_per_year' => 10, 'prorate_first_year' => false,
            'max_carry_forward_days' => 5, 'carry_forward_expiry_months' => 3,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function leave(string $from, float $days, string $status = LeaveRequest::STATUS_APPROVED): void
    {
        LeaveRequest::factory()->create([
            'student_id' => null, 'staff_id' => $this->staff->id, 'leave_type_id' => $this->annual->id,
            'from_date' => $from, 'to_date' => $from, 'days' => $days, 'day_part' => 'full', 'status' => $status,
        ]);
    }

    public function test_the_balance_list_shows_each_staff_members_year(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $this->leave('2026-03-02', 3);
        $this->leave('2026-11-02', 1.5, LeaveRequest::STATUS_PENDING);
        $this->leave('2025-06-01', 4);

        $row = collect($this->getJson('/api/v1/leave-balances?year=2026')->assertOk()->json('data'))
            ->firstWhere('staff.id', $this->staff->id);

        $this->assertSame($this->annual->id, $row['balances'][0]['leave_type']['id']);
        $this->assertEquals(10, $row['balances'][0]['entitlement']);
        $this->assertEquals(3, $row['balances'][0]['used']);
        $this->assertEquals(1.5, $row['balances'][0]['pending']);
        $this->assertEquals(5.5, $row['balances'][0]['available']);
    }

    public function test_hr_adjusts_a_balance_with_a_note_and_can_undo_it(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);

        $id = $this->postJson('/api/v1/leave-balances/adjustments', [
            'staff_id' => $this->staff->id, 'leave_type_id' => $this->annual->id, 'year' => 2026, 'days' => 2.5, 'note' => 'Worked on Khmer New Year',
        ])->assertCreated()->assertJsonPath('data.kind', 'adjustment')->json('data.id');

        $this->postJson('/api/v1/leave-balances/adjustments', [
            'staff_id' => $this->staff->id, 'leave_type_id' => $this->annual->id, 'year' => 2026, 'days' => 0.3, 'note' => '',
        ])->assertUnprocessable()->assertJsonValidationErrors(['days', 'note']);

        $this->getJson("/api/v1/leave-balances/{$this->staff->id}?year=2026")
            ->assertOk()
            ->assertJsonPath('data.balances.0.adjustments', 2.5)
            ->assertJsonPath('data.balances.0.available', 12.5)
            ->assertJsonPath('data.entries.0.note', 'Worked on Khmer New Year');

        $this->deleteJson("/api/v1/leave-balance-entries/{$id}")->assertNoContent();
        $this->getJson("/api/v1/leave-balances/{$this->staff->id}?year=2026")->assertJsonPath('data.balances.0.available', 10);
    }

    public function test_carry_forward_moves_unused_days_capped_and_can_be_run_again(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $this->leave('2026-03-02', 8);

        // 2 unused → 2 carried, expiring end of March 2027.
        $this->postJson('/api/v1/leave-balances/carry-forward', ['from_year' => 2026])
            ->assertOk()
            ->assertJsonPath('data.entries', 1)
            ->assertJsonPath('data.days', 2);
        $this->getJson('/api/v1/leave-balances/carry-forward?year=2027')
            ->assertOk()
            ->assertJsonPath('data.0.days', 2)
            ->assertJsonPath('data.0.expires_on', '2027-03-31')
            ->assertJsonPath('data.0.staff.id', $this->staff->id);

        // Run again after a request is rejected: replaced, capped at 5.
        LeaveRequest::query()->update(['status' => LeaveRequest::STATUS_REJECTED]);
        $this->postJson('/api/v1/leave-balances/carry-forward', ['from_year' => 2026])->assertJsonPath('data.days', 5);
        $this->assertSame(1, LeaveBalanceEntry::query()->where('year', 2027)->count());

        // A manual delete isn't how carried days go away.
        $this->deleteJson('/api/v1/leave-balance-entries/'.LeaveBalanceEntry::query()->value('id'))->assertUnprocessable();
    }

    public function test_carried_days_only_cover_leave_before_they_expire(): void
    {
        LeaveBalanceEntry::query()->create([
            'staff_id' => $this->staff->id, 'leave_type_id' => $this->annual->id, 'year' => 2026,
            'kind' => LeaveBalanceEntry::KIND_CARRY_FORWARD, 'days' => 4, 'expires_on' => '2026-03-31',
        ]);
        $this->leave('2026-02-02', 1);

        // Today (October): 3 of the 4 carried days lapsed at the end of March.
        $balance = app(LeaveBalanceService::class)->balance($this->staff, $this->annual, 2026);
        $this->assertEquals(4, $balance['carried_forward']);
        $this->assertEquals(3, $balance['carried_lapsed']);
        $this->assertEquals(11, $balance['total']);
        $this->assertEquals(10, $balance['available']);

        // Seen from February, all 4 still count.
        $february = app(LeaveBalanceService::class)->balance($this->staff, $this->annual, 2026, CarbonImmutable::parse('2026-02-15'));
        $this->assertEquals(13, $february['available']);
    }

    public function test_viewing_alone_cannot_adjust_or_carry_forward(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::LEAVE_MANAGEMENT_VIEW]);

        $this->getJson('/api/v1/leave-balances')->assertOk();
        $this->postJson('/api/v1/leave-balances/carry-forward', ['from_year' => 2026])->assertForbidden();
        $this->postJson('/api/v1/leave-balances/adjustments', [
            'staff_id' => $this->staff->id, 'leave_type_id' => $this->annual->id, 'year' => 2026, 'days' => 1, 'note' => 'x',
        ])->assertForbidden();
    }
}
