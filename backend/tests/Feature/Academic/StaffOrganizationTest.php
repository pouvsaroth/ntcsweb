<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Branch;
use App\Models\Department;
use App\Models\JobGrade;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Team;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * Where a staff member sits in HRM > Organization Management — see
 * ValidatesStaffOrganization — and the nesting of Branch > Department > Team.
 */
class StaffOrganizationTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private function position(): Position
    {
        return Position::factory()->create(['role_id' => Role::factory()->forTenant($this->tenant)->create()->id]);
    }

    public function test_a_new_staff_member_is_placed_in_the_organization(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_CREATE]);
        $branch = Branch::factory()->create(['name' => 'Main Campus']);
        $department = Department::factory()->create(['branch_id' => $branch->id, 'name' => 'Academic']);
        $team = Team::factory()->create(['department_id' => $department->id, 'name' => 'English']);
        $grade = JobGrade::factory()->create(['name' => 'G3']);
        $level = JobLevel::factory()->create(['name' => 'Senior']);
        $manager = Staff::factory()->create(['first_name' => 'Dara', 'last_name' => 'Sok']);

        $this->postJson('/api/v1/staff', [
            'first_name' => 'John',
            'last_name' => 'Smith',
            'phone' => '012345678',
            'position_id' => $this->position()->id,
            'branch_id' => $branch->id,
            'department_id' => $department->id,
            'team_id' => $team->id,
            'job_grade_id' => $grade->id,
            'job_level_id' => $level->id,
            'reports_to_staff_id' => $manager->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.branch.name', 'Main Campus')
            ->assertJsonPath('data.department.name', 'Academic')
            ->assertJsonPath('data.team.name', 'English')
            ->assertJsonPath('data.job_grade.name', 'G3')
            ->assertJsonPath('data.job_level.name', 'Senior')
            ->assertJsonPath('data.reports_to.full_name', 'Dara Sok');
    }

    public function test_the_picks_must_agree_with_each_other(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_CREATE]);
        [$branchA, $branchB] = Branch::factory()->count(2)->create();
        $departmentInA = Department::factory()->create(['branch_id' => $branchA->id]);
        $otherDepartment = Department::factory()->create();
        $teamInOther = Team::factory()->create(['department_id' => $otherDepartment->id]);

        $this->postJson('/api/v1/staff', [
            'first_name' => 'John',
            'last_name' => 'Smith',
            'phone' => '012345678',
            'position_id' => $this->position()->id,
            'branch_id' => $branchB->id,
            'department_id' => $departmentInA->id,
            'team_id' => $teamInOther->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['department_id', 'team_id']);
    }

    public function test_nobody_can_report_to_themselves_even_indirectly(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_UPDATE]);
        $boss = Staff::factory()->create();
        $middle = Staff::factory()->create(['reports_to_staff_id' => $boss->id]);
        $junior = Staff::factory()->create(['reports_to_staff_id' => $middle->id]);

        $this->putJson("/api/v1/staff/{$boss->id}", ['reports_to_staff_id' => $boss->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reports_to_staff_id');
        $this->putJson("/api/v1/staff/{$boss->id}", ['reports_to_staff_id' => $junior->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reports_to_staff_id');

        $this->putJson("/api/v1/staff/{$junior->id}", ['reports_to_staff_id' => $boss->id])
            ->assertOk()
            ->assertJsonPath('data.reports_to_staff_id', $boss->id);
    }

    public function test_sending_an_empty_value_clears_a_placement(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_UPDATE]);
        $staff = Staff::factory()->create(['job_grade_id' => JobGrade::factory()->create()->id]);

        $this->putJson("/api/v1/staff/{$staff->id}", ['job_grade_id' => ''])
            ->assertOk()
            ->assertJsonPath('data.job_grade_id', null);
    }

    public function test_a_unit_still_in_use_cannot_be_deleted(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ORGANIZATION_DELETE]);
        $branch = Branch::factory()->create();
        Department::factory()->create(['branch_id' => $branch->id]);
        $grade = JobGrade::factory()->create();
        Staff::factory()->create(['job_grade_id' => $grade->id]);
        $unusedLevel = JobLevel::factory()->create();

        $this->deleteJson("/api/v1/branches/{$branch->id}")->assertUnprocessable();
        $this->deleteJson("/api/v1/job-grades/{$grade->id}")->assertUnprocessable();
        $this->deleteJson("/api/v1/job-levels/{$unusedLevel->id}")->assertNoContent();
    }

    public function test_a_department_with_staff_or_teams_cannot_be_deleted(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ORGANIZATION_DELETE]);
        $withTeam = Department::factory()->create();
        Team::factory()->create(['department_id' => $withTeam->id]);
        $withStaff = Department::factory()->create();
        Staff::factory()->create(['department_id' => $withStaff->id]);

        $this->deleteJson("/api/v1/departments/{$withTeam->id}")->assertUnprocessable();
        $this->deleteJson("/api/v1/departments/{$withStaff->id}")->assertUnprocessable();
    }

    public function test_departments_sit_in_a_branch_and_teams_in_a_department(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ORGANIZATION_VIEW, Permissions::ORGANIZATION_CREATE]);
        $branch = Branch::factory()->create(['name' => 'Siem Reap']);

        $departmentId = $this->postJson('/api/v1/departments', ['code' => 'ACA', 'name' => 'Academic', 'branch_id' => $branch->id])
            ->assertCreated()
            ->assertJsonPath('data.branch.name', 'Siem Reap')
            ->json('data.id');

        $this->postJson('/api/v1/teams', ['code' => 'ENG', 'name' => 'English', 'department_id' => $departmentId])
            ->assertCreated()
            ->assertJsonPath('data.department.name', 'Academic');

        $this->getJson("/api/v1/departments?filter[branch_id]={$branch->id}")->assertOk()->assertJsonCount(1, 'data');
    }
}
