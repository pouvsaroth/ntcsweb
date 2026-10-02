<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Models\ApprovalGroup;
use App\Models\ApprovalGroupMember;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * Approval Flow → Groups — see ApprovalGroupController.
 */
class ApprovalGroupTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    /** Only staff accounts can be group members — see ApprovalGroup::eligibleUserIds(). */
    private function staffUser(array $attributes = [], string $staffStatus = Staff::STATUS_ACTIVE): User
    {
        $user = User::factory()->forTenant($this->tenant)->create($attributes);
        Staff::factory()->withUser($user)->create(['status' => $staffStatus]);

        return $user;
    }

    public function test_managing_groups_requires_the_manage_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $group = ApprovalGroup::factory()->create();

        $this->getJson('/api/v1/approval-groups')->assertForbidden();
        $this->getJson('/api/v1/approval-groups/users')->assertForbidden();
        $this->postJson('/api/v1/approval-groups', ['name' => 'Finance', 'user_ids' => []])->assertForbidden();
        $this->putJson("/api/v1/approval-groups/{$group->id}", ['name' => 'Finance', 'user_ids' => []])->assertForbidden();
        $this->deleteJson("/api/v1/approval-groups/{$group->id}")->assertForbidden();
    }

    public function test_a_group_can_be_created_with_members(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::APPROVAL_GROUPS_MANAGE]);
        $sokha = $this->staffUser(['name' => 'Sokha']);
        $dara = $this->staffUser(['name' => 'Dara']);

        $response = $this->postJson('/api/v1/approval-groups', [
            'name' => 'Academic Managers',
            'description' => 'Approve leave for teachers',
            'user_ids' => [$sokha->id, $dara->id],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Academic Managers');
        $response->assertJsonPath('data.members.0.name', 'Sokha');
        $response->assertJsonPath('data.members.1.name', 'Dara');
        $this->assertSame(2, ApprovalGroupMember::query()->count());
    }

    public function test_updating_a_group_replaces_its_member_list(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::APPROVAL_GROUPS_MANAGE]);
        [$kept, $removed, $added] = [$this->staffUser(), $this->staffUser(), $this->staffUser()];
        $group = ApprovalGroup::factory()->create(['name' => 'Finance']);
        $group->members()->create(['user_id' => $kept->id]);
        $group->members()->create(['user_id' => $removed->id]);

        $response = $this->putJson("/api/v1/approval-groups/{$group->id}", [
            'name' => 'Finance Team',
            'user_ids' => [$kept->id, $added->id],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Finance Team');
        $this->assertEqualsCanonicalizing([$kept->id, $added->id], $group->members()->pluck('user_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_the_list_shows_each_groups_members(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::APPROVAL_GROUPS_MANAGE]);
        $user = $this->staffUser(['name' => 'Sokha']);
        $group = ApprovalGroup::factory()->create(['name' => 'Finance']);
        $group->members()->create(['user_id' => $user->id]);
        ApprovalGroup::factory()->create(['name' => 'Academic']);

        $response = $this->getJson('/api/v1/approval-groups');

        $response->assertOk();
        $response->assertJsonPath('data.0.name', 'Academic');
        $response->assertJsonPath('data.0.members', []);
        $response->assertJsonPath('data.1.name', 'Finance');
        $response->assertJsonPath('data.1.members.0.name', 'Sokha');
    }

    public function test_users_of_another_school_cannot_be_added(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::APPROVAL_GROUPS_MANAGE]);
        $outsider = User::factory()->forTenant(Tenant::factory()->create())->create();

        $this->postJson('/api/v1/approval-groups', ['name' => 'Finance', 'user_ids' => [$outsider->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_ids.0']);
    }

    public function test_a_name_is_required(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::APPROVAL_GROUPS_MANAGE]);

        $this->postJson('/api/v1/approval-groups', ['user_ids' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_a_group_can_be_deleted(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::APPROVAL_GROUPS_MANAGE]);
        $group = ApprovalGroup::factory()->create();

        $this->deleteJson("/api/v1/approval-groups/{$group->id}")->assertNoContent();

        $this->assertSoftDeleted($group);
    }

    public function test_the_member_picker_lists_only_working_staff_of_this_school(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::APPROVAL_GROUPS_MANAGE]);
        $staff = $this->staffUser();
        $onLeave = $this->staffUser([], Staff::STATUS_ON_LEAVE);
        $resigned = $this->staffUser([], Staff::STATUS_RESIGNED);
        // Set after the staff record, which syncs the account's status from HR status.
        $inactiveAccount = $this->staffUser();
        $inactiveAccount->forceFill(['status' => User::STATUS_INACTIVE])->save();
        $student = User::factory()->forTenant($this->tenant)->create();
        Student::factory()->create(['user_id' => $student->id]);

        $ids = collect($this->getJson('/api/v1/approval-groups/users')->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($staff->id));
        $this->assertTrue($ids->contains($onLeave->id));
        $this->assertFalse($ids->contains($resigned->id));
        $this->assertFalse($ids->contains($inactiveAccount->id));
        $this->assertFalse($ids->contains($student->id));
        // The admin account itself has no staff record.
        $this->assertFalse($ids->contains($admin->id));
    }

    public function test_a_user_without_a_working_staff_record_cannot_be_added(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::APPROVAL_GROUPS_MANAGE]);
        $noStaffRecord = User::factory()->forTenant($this->tenant)->create();
        $resigned = $this->staffUser([], Staff::STATUS_RESIGNED);

        $this->postJson('/api/v1/approval-groups', ['name' => 'Finance', 'user_ids' => [$noStaffRecord->id, $resigned->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_ids.0', 'user_ids.1']);
    }

    public function test_a_member_who_has_since_left_can_stay_until_removed(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::APPROVAL_GROUPS_MANAGE]);
        $leaver = $this->staffUser();
        $group = ApprovalGroup::factory()->create();
        $group->members()->create(['user_id' => $leaver->id]);
        Staff::query()->where('user_id', $leaver->id)->update(['status' => Staff::STATUS_RESIGNED]);

        $this->putJson("/api/v1/approval-groups/{$group->id}", ['name' => 'Renamed', 'user_ids' => [$leaver->id]])->assertOk();
    }
}
