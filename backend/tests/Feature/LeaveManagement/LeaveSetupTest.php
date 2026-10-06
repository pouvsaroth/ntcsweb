<?php

declare(strict_types=1);

namespace Tests\Feature\LeaveManagement;

use App\Models\JobGrade;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\Staff;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * HRM > Leave Management's set-up — leave types and leave policies.
 */
class LeaveSetupTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const ALL = [Permissions::LEAVE_MANAGEMENT_VIEW, Permissions::LEAVE_MANAGEMENT_MANAGE];

    public function test_leave_types_are_created_with_unique_codes(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);

        $this->postJson('/api/v1/leave-types', ['code' => 'AL', 'name' => 'Annual leave', 'color' => '#22c55e'])
            ->assertCreated()
            ->assertJsonPath('data.is_paid', true)
            ->assertJsonPath('data.allow_half_day', true)
            ->assertJsonPath('data.gender', null);

        $this->postJson('/api/v1/leave-types', ['code' => 'ML', 'name' => 'Maternity leave', 'gender' => 'female', 'allow_half_day' => false])
            ->assertCreated()
            ->assertJsonPath('data.gender', 'female');

        $this->postJson('/api/v1/leave-types', ['code' => 'AL', 'name' => 'Again', 'gender' => 'nobody', 'color' => 'green'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code', 'gender', 'color']);

        $this->getJson('/api/v1/leave-types')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_leave_type_with_policies_cannot_be_deleted(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $used = LeaveType::factory()->create();
        $unused = LeaveType::factory()->create();
        LeavePolicy::factory()->create(['leave_type_id' => $used->id]);

        $this->deleteJson("/api/v1/leave-types/{$used->id}")->assertUnprocessable();
        $this->deleteJson("/api/v1/leave-types/{$unused->id}")->assertNoContent();
    }

    public function test_policies_count_in_half_days_and_one_is_active_per_type_and_grade(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $type = LeaveType::factory()->create();
        $grade = JobGrade::factory()->create();

        $this->postJson('/api/v1/leave-policies', [
            'leave_type_id' => $type->id, 'name' => 'Everyone', 'days_per_year' => 18,
            'service_bonus_every_years' => 3, 'service_bonus_days' => 1, 'max_carry_forward_days' => 5,
        ])->assertCreated()
            ->assertJsonPath('data.leave_type.id', $type->id)
            ->assertJsonPath('data.job_grade', null);

        // A second everyone-policy for the same type clashes; one for a grade doesn't.
        $this->postJson('/api/v1/leave-policies', ['leave_type_id' => $type->id, 'name' => 'Again', 'days_per_year' => 10])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['job_grade_id']);
        $this->postJson('/api/v1/leave-policies', ['leave_type_id' => $type->id, 'job_grade_id' => $grade->id, 'name' => 'Managers', 'days_per_year' => 21.5])
            ->assertCreated()
            ->assertJsonPath('data.job_grade.id', $grade->id);

        // An inactive duplicate is fine — it never applies.
        $this->postJson('/api/v1/leave-policies', ['leave_type_id' => $type->id, 'name' => 'Old', 'days_per_year' => 12, 'is_active' => false])
            ->assertCreated();

        $this->postJson('/api/v1/leave-policies', ['leave_type_id' => $type->id, 'name' => 'Bad', 'days_per_year' => 2.3, 'is_active' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['days_per_year']);
    }

    public function test_a_grade_policy_wins_over_the_everyone_policy(): void
    {
        $type = LeaveType::factory()->create();
        $grade = JobGrade::factory()->create();
        $everyone = LeavePolicy::factory()->create(['leave_type_id' => $type->id, 'days_per_year' => 18]);
        $managers = LeavePolicy::factory()->create(['leave_type_id' => $type->id, 'job_grade_id' => $grade->id, 'days_per_year' => 21]);

        $manager = Staff::factory()->create(['job_grade_id' => $grade->id]);
        $clerk = Staff::factory()->create(['job_grade_id' => null]);

        $this->assertSame($managers->id, LeavePolicy::forStaff($manager, $type->id)?->id);
        $this->assertSame($everyone->id, LeavePolicy::forStaff($clerk, $type->id)?->id);

        $managers->update(['is_active' => false]);
        $this->assertSame($everyone->id, LeavePolicy::forStaff($manager, $type->id)?->id);
    }

    public function test_viewing_alone_cannot_change_the_set_up(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::LEAVE_MANAGEMENT_VIEW]);
        $type = LeaveType::factory()->create();

        $this->getJson('/api/v1/leave-types')->assertOk();
        $this->getJson('/api/v1/leave-policies')->assertOk();
        $this->postJson('/api/v1/leave-types', ['code' => 'X', 'name' => 'X'])->assertForbidden();
        $this->postJson('/api/v1/leave-policies', ['leave_type_id' => $type->id, 'name' => 'X', 'days_per_year' => 1])->assertForbidden();
    }

    public function test_without_permission_nothing_is_visible(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/v1/leave-types')->assertForbidden();
        $this->getJson('/api/v1/leave-policies')->assertForbidden();
    }
}
