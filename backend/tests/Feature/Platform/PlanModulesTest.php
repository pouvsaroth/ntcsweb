<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Platform\Billing\Modules;
use App\Platform\Models\Plan;
use App\Platform\Models\SchoolSubscription;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

/**
 * Stage 5c (docs/backoffice/stage-5-billing.md): a school's plan switches
 * console modules on and off, through the one permission seam.
 */
class PlanModulesTest extends TestCase
{
    use HasAcademicAdmin, HasPlatformStaff, RefreshDatabase;

    /** @param list<string> $modules */
    private function putOnPlan(array $modules, string $status = 'active', ?array $menus = null): Plan
    {
        $plan = Plan::query()->create([
            'code' => 'p-'.fake()->unique()->numberBetween(1, 99999), 'name' => 'Plan', 'price' => 10, 'currency' => 'USD',
            'period_months' => 1, 'modules' => $modules, 'menus' => $menus, 'status' => Plan::STATUS_ACTIVE,
        ]);
        SchoolSubscription::query()->where('tenant_id', $this->tenant->id)->live()->update(['status' => SchoolSubscription::REPLACED]);
        SchoolSubscription::query()->create([
            'tenant_id' => $this->tenant->id, 'plan_id' => $plan->id, 'status' => $status,
            'start_on' => now()->toDateString(), 'end_on' => now()->addMonth()->toDateString(),
        ]);

        return $plan;
    }

    public function test_a_school_with_no_subscription_keeps_every_module(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PAYROLL_VIEW, Permissions::STUDENTS_VIEW]);

        $this->getJson('/api/v1/payroll-rules')->assertOk();
        $this->getJson('/api/v1/students')->assertOk();
    }

    public function test_a_module_the_plan_lacks_is_refused_and_dropped_from_the_users_permissions(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PAYROLL_VIEW, Permissions::STUDENTS_VIEW, Permissions::USERS_VIEW]);
        $this->putOnPlan(['academics']);

        $this->getJson('/api/v1/payroll-rules')->assertForbidden();
        $this->getJson('/api/v1/students')->assertOk();

        $permissions = $this->getJson('/api/v1/auth/me')->assertOk()->json('meta.permissions');
        $this->assertContains(Permissions::STUDENTS_VIEW, $permissions);
        $this->assertContains(Permissions::USERS_VIEW, $permissions, 'core is always on');
        $this->assertNotContains(Permissions::PAYROLL_VIEW, $permissions);
    }

    public function test_adding_the_module_to_the_plan_turns_it_back_on_at_once(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PAYROLL_VIEW]);
        $plan = $this->putOnPlan(['academics']);
        $this->getJson('/api/v1/payroll-rules')->assertForbidden();

        $plan->update(['modules' => ['academics', 'payroll']]);

        $this->getJson('/api/v1/payroll-rules')->assertOk();
    }

    public function test_a_menu_the_plan_leaves_out_is_refused_while_the_rest_of_its_module_works(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_VIEW, Permissions::CLASSES_VIEW, Permissions::USERS_VIEW]);
        $this->putOnPlan(['academics'], menus: ['students', 'enrollments']);

        $this->getJson('/api/v1/students')->assertOk();
        $this->getJson('/api/v1/classes')->assertForbidden();

        $permissions = $this->getJson('/api/v1/auth/me')->assertOk()->json('meta.permissions');
        $this->assertContains(Permissions::STUDENTS_VIEW, $permissions);
        $this->assertNotContains(Permissions::CLASSES_VIEW, $permissions);
        $this->assertContains(Permissions::USERS_VIEW, $permissions, 'core is always on');
    }

    public function test_staff_pick_menus_and_a_plan_with_every_menu_stores_none(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $body = ['code' => 'menu-plan', 'name' => 'Menu plan', 'price' => 5, 'currency' => 'USD', 'period_months' => 1];

        // Menus of modules not in the plan are dropped; order follows the catalog.
        $id = $this->postJson('/api/v1/platform/billing/plans', $body + ['modules' => ['academics'], 'menus' => ['students', 'classes', 'payroll']])
            ->assertCreated()->assertJsonPath('data.menus', ['classes', 'students'])->json('data.id');

        // Every menu of each module ticked = stored as null ("everything").
        $this->patchJson("/api/v1/platform/billing/plans/{$id}", ['modules' => ['payroll'], 'menus' => ['payroll']])
            ->assertOk()->assertJsonPath('data.menus', null);

        $this->postJson('/api/v1/platform/billing/plans', $body + ['code' => 'bad', 'modules' => ['academics'], 'menus' => ['nope']])
            ->assertUnprocessable()->assertJsonValidationErrors('menus.0');

        $catalog = collect($this->getJson('/api/v1/platform/billing/plans')->json('data.modules'))->firstWhere('code', 'academics');
        $this->assertSame('Students', collect($catalog['menus'])->firstWhere('code', 'students')['label']);
    }

    public function test_every_module_menu_has_a_label(): void
    {
        foreach (Modules::groups() as $group) {
            $this->assertArrayHasKey($group, Modules::MENU_LABELS, "No label for menu {$group}");
        }
    }

    public function test_school_dashboard_tiles_follow_their_modules(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::DASHBOARD_VIEW, Permissions::DASHBOARD_CARDS_ENROLLMENT, Permissions::DASHBOARD_FINANCE_VIEW, Permissions::DASHBOARD_CARDS_TEACHER_ATTENDANCE, Permissions::DASHBOARD_CARDS_USERS]);
        $this->putOnPlan(['hrm', 'payroll']); // an ERP/HR-only customer

        $permissions = $this->getJson('/api/v1/auth/me')->assertOk()->json('meta.permissions');
        $this->assertContains(Permissions::DASHBOARD_VIEW, $permissions);
        $this->assertContains(Permissions::DASHBOARD_CARDS_USERS, $permissions, 'core tiles stay');
        $this->assertContains(Permissions::DASHBOARD_CARDS_TEACHER_ATTENDANCE, $permissions, 'staff attendance is HRM');
        $this->assertNotContains(Permissions::DASHBOARD_CARDS_ENROLLMENT, $permissions);
        $this->assertNotContains(Permissions::DASHBOARD_FINANCE_VIEW, $permissions);
    }

    public function test_every_module_slug_is_a_real_permission(): void
    {
        $all = collect(Permissions::catalog())->flatMap(fn ($group) => array_keys($group))->all();
        foreach (array_keys(Modules::SLUG_MODULES) as $slug) {
            $this->assertContains($slug, $all, "{$slug} is not a permission");
        }
    }

    public function test_newtechs_own_website_school_is_never_limited_by_its_plan(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_VIEW]);
        config(['platform.website_school' => $this->tenant->slug]);
        $this->putOnPlan(['academics']); // no Website module

        $modules = app(\App\Platform\Billing\SchoolModules::class);
        $modules->forget();
        $this->assertNull($modules->enabled($this->tenant->id));
        $this->assertTrue($modules->has($this->tenant->id, 'website'));
    }

    public function test_an_expired_plan_keeps_its_modules_through_grace(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PAYROLL_VIEW]);
        $this->putOnPlan(['payroll'], SchoolSubscription::EXPIRED);

        $this->getJson('/api/v1/payroll-rules')->assertOk();
    }

    public function test_super_admins_are_not_limited_by_the_plan(): void
    {
        // A super admin belongs to no school (tenant_id null), and Gate::before
        // grants them everything before any permission is read. The filter
        // itself also leaves a school-less user untouched.
        $this->actingAsAdminWithPermissions([]);
        $this->putOnPlan([]);

        $modules = app(\App\Platform\Billing\SchoolModules::class);
        $this->assertSame([Permissions::PAYROLL_VIEW], $modules->filter(null, [Permissions::PAYROLL_VIEW]));
        $this->assertSame([], $modules->filter($this->tenant->id, [Permissions::PAYROLL_VIEW]));
    }

    public function test_the_public_website_is_gone_without_the_website_module_but_settings_stay(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->app['auth']->forgetGuards();
        $public = fn (string $uri) => $this->withHeader('X-Tenant', $this->tenant->slug)->getJson("/api/v1/public/{$uri}");

        $public('home-slides')->assertOk();
        $public('settings')->assertOk()->assertJsonPath('data.website_enabled', true);

        $this->putOnPlan(['academics']);

        $public('home-slides')->assertNotFound();
        $public('gallery')->assertNotFound();
        $public('programs')->assertNotFound();
        $public('settings')->assertOk()->assertJsonPath('data.website_enabled', false);
        $public('geo/provinces')->assertOk();
    }

    public function test_every_module_group_is_a_real_permission_group(): void
    {
        $groups = collect(Permissions::catalog())->flatMap(fn ($permissions) => array_keys($permissions))
            ->map(fn (string $slug) => strstr($slug, '.', true))
            ->unique();

        foreach (Modules::CATALOG as $code => $module) {
            foreach ($module['groups'] as $group) {
                $this->assertTrue($groups->contains($group), "Module {$code} lists '{$group}', which no permission uses.");
            }
        }
    }
}
