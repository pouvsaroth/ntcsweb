<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * Roles step 5a: Organization Management's lists each have their own
 * Add / Update / Delete; Departments has its own View too.
 */
class OrganizationPermissionsSplitTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_each_list_has_its_own_add_update_and_delete(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ORGANIZATION_VIEW, Permissions::BRANCHES_CREATE]);
        $id = $this->postJson('/api/v1/branches', ['code' => 'PP', 'name' => 'Phnom Penh'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/teams', ['code' => 'T1', 'name' => 'Team 1'])->assertForbidden();
        $this->putJson("/api/v1/branches/{$id}", ['code' => 'PP', 'name' => 'PP'])->assertForbidden();
        $this->deleteJson("/api/v1/branches/{$id}")->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::ORGANIZATION_VIEW, Permissions::BRANCHES_UPDATE, Permissions::BRANCHES_DELETE]);
        $this->putJson("/api/v1/branches/{$id}", ['code' => 'PP', 'name' => 'PP'])->assertOk();
        $this->deleteJson("/api/v1/branches/{$id}")->assertNoContent();
    }

    public function test_departments_have_their_own_permissions(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_VIEW, Permissions::ASSETS_CREATE]);
        // Asset staff still pick a department.
        $this->getJson('/api/v1/departments')->assertOk();
        $this->postJson('/api/v1/departments', ['code' => 'IT', 'name' => 'IT'])->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::DEPARTMENTS_VIEW, Permissions::DEPARTMENTS_CREATE]);
        $this->postJson('/api/v1/departments', ['code' => 'IT', 'name' => 'IT'])->assertCreated();
    }

    public function test_school_admin_has_every_new_permission_by_default(): void
    {
        $defaults = Permissions::defaultsForSystemRoles()['school-admin'];
        foreach ([Permissions::BRANCHES_DELETE, Permissions::DEPARTMENTS_VIEW, Permissions::DEPARTMENTS_DELETE, Permissions::TEAMS_DELETE, Permissions::JOB_GRADES_DELETE, Permissions::JOB_LEVELS_DELETE] as $slug) {
            $this->assertContains($slug, $defaults);
        }
    }
}
