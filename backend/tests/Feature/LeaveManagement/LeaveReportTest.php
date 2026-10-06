<?php

declare(strict_types=1);

namespace Tests\Feature\LeaveManagement;

use App\Models\ApprovalFlowStep;
use App\Models\ApprovalGroup;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Staff;
use App\Support\Approvals\DocumentType;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * HRM > Leave Management's Leave calendar, Leave reports and Approval
 * workflow views.
 */
class LeaveReportTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private Staff $ann;

    private Staff $bo;

    private LeaveType $annual;

    private LeaveType $sick;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 09:00:00');

        $this->ann = Staff::factory()->create(['first_name' => 'Ann']);
        $this->bo = Staff::factory()->create(['first_name' => 'Bo']);
        $this->annual = LeaveType::factory()->create(['name' => 'Annual leave']);
        $this->sick = LeaveType::factory()->create(['name' => 'Sick leave']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function leave(Staff $staff, LeaveType $type, string $from, string $to, float $days, string $status = LeaveRequest::STATUS_APPROVED): LeaveRequest
    {
        return LeaveRequest::factory()->create([
            'student_id' => null, 'staff_id' => $staff->id, 'leave_type_id' => $type->id,
            'from_date' => $from, 'to_date' => $to, 'days' => $days, 'day_part' => 'full', 'status' => $status,
        ]);
    }

    public function test_the_calendar_shows_a_months_leave_and_holidays(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::LEAVE_MANAGEMENT_VIEW]);
        $this->leave($this->ann, $this->annual, '2026-09-29', '2026-10-02', 4);
        $this->leave($this->bo, $this->sick, '2026-10-12', '2026-10-12', 1, LeaveRequest::STATUS_PENDING);
        $this->leave($this->bo, $this->sick, '2026-10-20', '2026-10-20', 1, LeaveRequest::STATUS_REJECTED);
        $this->leave($this->bo, $this->annual, '2026-11-02', '2026-11-02', 1);
        Holiday::query()->create(['name' => 'Pchum Ben', 'start_date' => '2026-10-10', 'end_date' => '2026-10-12']);

        $this->getJson('/api/v1/leave-calendar?month=2026-10')
            ->assertOk()
            ->assertJsonPath('data.from', '2026-10-01')
            ->assertJsonPath('data.to', '2026-10-31')
            ->assertJsonCount(1, 'data.leaves')
            ->assertJsonPath('data.leaves.0.staff.id', $this->ann->id)
            ->assertJsonCount(1, 'data.holidays');

        $this->getJson('/api/v1/leave-calendar?month=2026-10&include_pending=1')->assertJsonCount(2, 'data.leaves');
        $this->getJson('/api/v1/leave-calendar?month=2026-10&include_pending=1&leave_type_id='.$this->sick->id)->assertJsonCount(1, 'data.leaves');
        $this->getJson('/api/v1/leave-calendar?month=October')->assertUnprocessable();
    }

    public function test_the_report_totals_a_years_approved_leave_by_type_month_and_staff(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::LEAVE_MANAGEMENT_VIEW]);
        $this->leave($this->ann, $this->annual, '2026-03-02', '2026-03-04', 3);
        $this->leave($this->ann, $this->sick, '2026-03-10', '2026-03-10', 1);
        $this->leave($this->bo, $this->annual, '2026-07-06', '2026-07-07', 1.5);
        $this->leave($this->bo, $this->annual, '2026-11-02', '2026-11-02', 1, LeaveRequest::STATUS_PENDING);
        $this->leave($this->bo, $this->annual, '2025-12-01', '2025-12-01', 1);

        $data = $this->getJson('/api/v1/leave-reports?year=2026')->assertOk()->json('data');

        $this->assertEquals(5.5, $data['totals']['days']);
        $this->assertSame(3, $data['totals']['requests']);
        $this->assertSame(2, $data['totals']['staff']);
        $this->assertSame(1, $data['totals']['pending_requests']);

        $annual = collect($data['by_type'])->firstWhere('leave_type.id', $this->annual->id);
        $this->assertEquals(4.5, $annual['days']);
        $this->assertSame(2, $annual['staff']);

        $this->assertEquals(4, $data['by_month'][2]['days']);
        $this->assertEquals(1.5, $data['by_month'][6]['days']);

        $this->assertSame($this->ann->id, $data['by_staff'][0]['staff']['id']);
        $this->assertEquals(4, $data['by_staff'][0]['days']);
        $this->assertEquals(3, $data['by_staff'][0]['by_type'][$this->annual->id]);

        $this->getJson('/api/v1/leave-reports?year=2026&leave_type_id='.$this->sick->id)->assertJsonPath('data.totals.days', 1);
    }

    public function test_the_workflow_shows_the_staff_leave_flow_and_whats_waiting(): void
    {
        $user = $this->actingAsAdminWithPermissions([Permissions::LEAVE_MANAGEMENT_VIEW]);
        $this->getJson('/api/v1/leave-workflow')->assertOk()->assertJsonCount(0, 'data.steps');

        $group = ApprovalGroup::factory()->create(['name' => 'HR managers']);
        $group->members()->create(['user_id' => $user->id]);
        ApprovalFlowStep::query()->create(['document_type' => DocumentType::STAFF_LEAVE, 'step_order' => 1, 'approval_group_id' => $group->id]);
        $this->leave($this->ann, $this->annual, '2026-10-12', '2026-10-12', 1, LeaveRequest::STATUS_PENDING);
        $this->leave($this->bo, $this->annual, '2026-03-12', '2026-03-12', 1);

        $this->getJson('/api/v1/leave-workflow')
            ->assertOk()
            ->assertJsonPath('data.steps.0.group.name', 'HR managers')
            ->assertJsonPath('data.steps.0.group.members.0', $user->name)
            ->assertJsonPath('data.counts.pending', 1)
            ->assertJsonPath('data.counts.approved', 1);
    }

    public function test_without_the_view_permission_none_of_it_is_visible(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/v1/leave-calendar?month=2026-10')->assertForbidden();
        $this->getJson('/api/v1/leave-reports')->assertForbidden();
        $this->getJson('/api/v1/leave-workflow')->assertForbidden();
    }
}
