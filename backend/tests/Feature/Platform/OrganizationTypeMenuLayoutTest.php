<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Models\OrganizationType;
use App\Platform\Services\SchoolDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\FakeSchoolDatabase;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

/** Back office > Organization types > Menu layout: each type's own sidebar. */
class OrganizationTypeMenuLayoutTest extends TestCase
{
    use HasPlatformStaff, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(SchoolDatabase::class, new FakeSchoolDatabase);
        Notification::fake();
    }

    private function type(string $code): OrganizationType
    {
        return OrganizationType::query()->where('code', $code)->firstOrFail();
    }

    /** Staff moved from HRM into a new Politic group, one Organization tab taken out, tabs reordered. */
    private function layout(): array
    {
        return [
            'groups' => [
                ['key' => 'custom:politic', 'names' => ['en' => 'Politic', 'km' => 'នយោបាយ', 'zh' => ' '], 'icon' => 'flag', 'items' => [
                    ['to' => '/admin/staff', 'from' => 'adminNav.groups.staff', 'names' => ['en' => 'Members']],
                    ['to' => '/admin/organization/teams', 'from' => 'tab'],
                ]],
                ['key' => 'adminNav.groups.staff', 'items' => [
                    ['to' => '/admin/payroll', 'hidden' => true],
                ]],
                ['key' => 'adminNav.groups.students', 'hidden' => true, 'items' => []],
            ],
            'tabs' => ['/admin/organization/school' => ['/admin/organization/branches', '/admin/organization/school']],
        ];
    }

    public function test_staff_save_a_layout_and_the_customers_console_gets_it(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $party = $this->type('company');

        $this->putJson("/api/v1/platform/organization-types/{$party->id}/menu-layout", ['menu_layout' => $this->layout()])
            ->assertOk()
            ->assertJsonPath('data.menu_layout.groups.0.key', 'custom:politic')
            // Blank names are dropped; untouched flags are not stored.
            ->assertJsonPath('data.menu_layout.groups.0.names', ['en' => 'Politic', 'km' => 'នយោបាយ'])
            ->assertJsonMissingPath('data.menu_layout.groups.0.items.1.hidden')
            ->assertJsonPath('data.menu_layout.groups.0.items.1.from', 'tab')
            ->assertJsonPath('data.menu_layout.groups.1.items.0.hidden', true)
            ->assertJsonPath('data.menu_layout.tabs./admin/organization/school.0', '/admin/organization/branches');

        $this->getJson("/api/v1/platform/organization-types/{$party->id}/menu-layout")
            ->assertOk()->assertJsonPath('data.menu_layout.groups.0.items.0.names.en', 'Members');
        $this->getJson('/api/v1/platform/organization-types')->assertOk()->assertJsonPath('data.1.has_menu_layout', true);
    }

    public function test_the_customers_console_gets_its_types_layout(): void
    {
        $party = $this->type('company');
        $party->update(['menu_layout' => ['groups' => [['key' => 'custom:politic', 'names' => ['en' => 'Politic'], 'items' => [['to' => '/admin/staff']]]], 'tabs' => []]]);
        $tenant = Tenant::factory()->create(['organization_type_id' => $party->id]);
        $this->actingAsTenantUser(User::factory()->forTenant($tenant)->create());
        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('meta.tenant.organization.menu_layout.groups.0.items.0.to', '/admin/staff');

        // No type set is a School: the School type's layout, the standard menu while it has none.
        $noType = Tenant::factory()->create();
        $this->actingAsTenantUser(User::factory()->forTenant($noType)->create());
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('meta.tenant.organization.menu_layout', null);

        $this->type('school')->update(['menu_layout' => ['groups' => [['key' => 'adminNav.groups.staff', 'hidden' => true, 'items' => []]]]]);
        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('meta.tenant.organization.code', 'school')
            ->assertJsonPath('meta.tenant.organization.menu_layout.groups.0.key', 'adminNav.groups.staff');
    }

    public function test_null_puts_back_the_standard_menu(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $type = $this->type('company');
        $type->update(['menu_layout' => ['groups' => [], 'tabs' => []]]);

        $this->putJson("/api/v1/platform/organization-types/{$type->id}/menu-layout", ['menu_layout' => null])
            ->assertOk()->assertJsonPath('data.menu_layout', null);
        $this->assertNull($type->fresh()->menu_layout);
    }

    public function test_a_broken_layout_is_refused(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $url = "/api/v1/platform/organization-types/{$this->type('company')->id}/menu-layout";

        // A new group needs an English name.
        $this->putJson($url, ['menu_layout' => ['groups' => [['key' => 'custom:politic', 'items' => []]]]])
            ->assertUnprocessable()->assertJsonValidationErrors('groups.0.names.en');
        // One item in two groups.
        $this->putJson($url, ['menu_layout' => ['groups' => [
            ['key' => 'custom:a', 'names' => ['en' => 'A'], 'items' => [['to' => '/admin/staff', 'from' => 'adminNav.groups.staff']]],
            ['key' => 'custom:b', 'names' => ['en' => 'B'], 'items' => [['to' => '/admin/staff', 'from' => 'adminNav.groups.staff']]],
        ]]])->assertUnprocessable()->assertJsonValidationErrors('groups.1.items.0.to');
        // The same path from two groups is fine (Vendors and Suppliers share /admin/suppliers).
        $this->putJson($url, ['menu_layout' => ['groups' => [
            ['key' => 'custom:a', 'names' => ['en' => 'A'], 'items' => [
                ['to' => '/admin/suppliers', 'from' => 'adminNav.groups.accounting'], ['to' => '/admin/suppliers', 'from' => 'adminNav.groups.assets'],
            ]],
        ]]])->assertOk();
        // Links stay inside the console.
        $this->putJson($url, ['menu_layout' => ['groups' => [
            ['key' => 'adminNav.groups.staff', 'items' => [['to' => 'https://example.com']]],
        ]]])->assertUnprocessable()->assertJsonValidationErrors('groups.0.items.0.to');
        // The same group twice.
        $this->putJson($url, ['menu_layout' => ['groups' => [
            ['key' => 'adminNav.groups.staff', 'items' => []], ['key' => 'adminNav.groups.staff', 'items' => []],
        ]]])->assertUnprocessable()->assertJsonValidationErrors('groups.0.key');
    }

    /** Without write access the back office hides the action (404, like every staff permission). */
    public function test_saving_needs_write_access(): void
    {
        $this->actingAsStaff($this->platformStaff(['platform.school.read']));

        $this->putJson("/api/v1/platform/organization-types/{$this->type('company')->id}/menu-layout", ['menu_layout' => null])
            ->assertNotFound();
    }
}
