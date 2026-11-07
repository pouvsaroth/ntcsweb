<?php

declare(strict_types=1);

namespace Tests\Feature\StaffAttendance;

use App\Models\LeaveType;
use App\Models\LookupCategory;
use App\Models\LookupValue;
use App\Models\Shift;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * Roles step 3b: Time & Attendance and Leave tabs each have their own
 * Add / Update / Delete instead of one staff-attendance.manage /
 * leave-management.manage.
 */
class TimeAttendanceLeavePermissionsSplitTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_shift_add_update_and_delete_are_separate(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_ATTENDANCE_VIEW, Permissions::SHIFTS_CREATE]);
        $this->postJson('/api/v1/shifts', ['code' => 'DAY', 'name' => 'Day', 'start_time' => '08:00', 'end_time' => '17:00'])->assertCreated();
        $shift = Shift::factory()->create();
        $this->putJson("/api/v1/shifts/{$shift->id}", ['name' => 'Morning'])->assertForbidden();
        $this->deleteJson("/api/v1/shifts/{$shift->id}")->assertForbidden();
        // Another tab's Add is not this one's.
        $this->postJson('/api/v1/holidays', ['name' => 'X', 'holiday_type' => 'PUBLIC_HOLIDAY', 'start_date' => '2026-12-25'])->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::STAFF_ATTENDANCE_VIEW, Permissions::SHIFTS_UPDATE]);
        $this->putJson("/api/v1/shifts/{$shift->id}", ['name' => 'Morning'])->assertOk();
        $this->postJson('/api/v1/shifts', ['code' => 'N', 'name' => 'N', 'start_time' => '08:00', 'end_time' => '17:00'])->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::STAFF_ATTENDANCE_VIEW, Permissions::SHIFTS_DELETE]);
        $this->deleteJson("/api/v1/shifts/{$shift->id}")->assertNoContent();
    }

    public function test_holidays_have_their_own_add(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_ATTENDANCE_VIEW, Permissions::HOLIDAYS_CREATE]);
        $category = LookupCategory::query()->create(['code' => 'HOLIDAY_TYPE', 'name' => 'Holiday Type']);
        LookupValue::query()->create(['lookup_category_id' => $category->id, 'code' => 'PUBLIC_HOLIDAY', 'sort_order' => 1]);
        $this->postJson('/api/v1/holidays', ['name' => 'Khmer New Year', 'holiday_type' => 'PUBLIC_HOLIDAY', 'start_date' => '2027-04-14'])->assertCreated();
        $this->postJson('/api/v1/shifts', ['code' => 'DAY', 'name' => 'Day', 'start_time' => '08:00', 'end_time' => '17:00'])->assertForbidden();
    }

    public function test_leave_types_and_policies_are_separate_tabs(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::LEAVE_MANAGEMENT_VIEW, Permissions::LEAVE_TYPES_CREATE]);
        $this->postJson('/api/v1/leave-types', ['code' => 'AL', 'name' => 'Annual leave'])->assertCreated();
        $type = LeaveType::factory()->create();
        $this->postJson('/api/v1/leave-policies', ['leave_type_id' => $type->id, 'name' => 'All', 'days_per_year' => 18])->assertForbidden();
        $this->deleteJson("/api/v1/leave-types/{$type->id}")->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::LEAVE_MANAGEMENT_VIEW, Permissions::LEAVE_POLICIES_CREATE, Permissions::LEAVE_TYPES_DELETE]);
        $this->postJson('/api/v1/leave-policies', ['leave_type_id' => $type->id, 'name' => 'All', 'days_per_year' => 18])->assertCreated();
        $other = LeaveType::factory()->create();
        $this->deleteJson("/api/v1/leave-types/{$other->id}")->assertNoContent();
    }

    public function test_view_alone_changes_nothing(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_ATTENDANCE_VIEW, Permissions::LEAVE_MANAGEMENT_VIEW]);
        $this->getJson('/api/v1/shifts')->assertOk();
        $this->postJson('/api/v1/shifts', ['code' => 'DAY', 'name' => 'Day', 'start_time' => '08:00', 'end_time' => '17:00'])->assertForbidden();
        $this->postJson('/api/v1/leave-types', ['code' => 'AL', 'name' => 'Annual leave'])->assertForbidden();
    }

    public function test_school_admin_has_every_new_permission_by_default(): void
    {
        $defaults = Permissions::defaultsForSystemRoles()['school-admin'];
        foreach ([
            Permissions::CHECK_INS_DELETE, Permissions::SHIFTS_DELETE, Permissions::STAFF_SHIFTS_DELETE, Permissions::HOLIDAYS_DELETE,
            Permissions::OVERTIME_REQUESTS_CREATE, Permissions::ATTENDANCE_CORRECTIONS_DELETE, Permissions::LEAVE_TYPES_DELETE,
            Permissions::LEAVE_POLICIES_DELETE, Permissions::LEAVE_BALANCES_DELETE, Permissions::LEAVE_CARRY_FORWARD_RUN,
            Permissions::LEAVE_REQUESTS_CREATE,
        ] as $slug) {
            $this->assertContains($slug, $defaults);
        }
    }
}
