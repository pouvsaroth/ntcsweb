<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\Authorization\TabVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * Settings > Roles' Show box per tab: a hidden tab is a `hide-tab.*` mark
 * saved on the role and sent to the app with the user's permissions.
 */
class HiddenTabsTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_every_tab_mark_is_a_hide_tab_permission_in_the_catalog(): void
    {
        $catalog = Permissions::catalog()['Hidden tabs'];

        $this->assertNotEmpty(TabVisibility::TABS);
        foreach (array_keys(TabVisibility::TABS) as $slug) {
            $this->assertStringStartsWith('hide-tab.', $slug);
            $this->assertArrayHasKey($slug, $catalog);
        }
    }

    public function test_a_role_saves_a_hidden_tab_and_its_users_receive_it(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::ROLES_UPDATE]);
        $admin->cachedRoles()->first()->update(['level' => 80]);
        $admin->forgetPermissionCache();
        $role = Role::factory()->forTenant($this->tenant)->create(['name' => 'Payroll clerk', 'slug' => 'payroll-clerk', 'level' => 30]);

        $this->putJson("/api/v1/roles/{$role->id}", [
            'permissions' => [Permissions::PAYROLL_VIEW, 'hide-tab.payroll.tax'],
        ])->assertOk();

        $this->assertEqualsCanonicalizing([Permissions::PAYROLL_VIEW, 'hide-tab.payroll.tax'], $role->fresh()->permissions()->pluck('slug')->all());

        $clerk = User::factory()->forTenant($this->tenant)->create();
        $clerk->attachRoles($role);
        $permissions = $this->actingAsTenantUser($clerk)->getJson('/api/v1/auth/me')->assertOk()->json('data.permissions');
        $this->assertContains('hide-tab.payroll.tax', $permissions);
        $this->assertContains(Permissions::PAYROLL_VIEW, $permissions);
    }

    public function test_an_unknown_tab_mark_is_refused(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::ROLES_UPDATE]);
        $admin->cachedRoles()->first()->update(['level' => 80]);
        $admin->forgetPermissionCache();
        $role = Role::factory()->forTenant($this->tenant)->create(['name' => 'Clerk', 'slug' => 'clerk', 'level' => 30]);

        $this->putJson("/api/v1/roles/{$role->id}", ['permissions' => ['hide-tab.no-such-tab']])
            ->assertUnprocessable()->assertJsonValidationErrors(['permissions.0']);
    }
}
