<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Branch;
use App\Models\JobGrade;
use App\Models\JobLevel;
use App\Models\Staff;
use App\Models\Team;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * HRM > Organization Management's lists — see OrganizationUnitController.
 */
class OrganizationUnitTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    /** @return array<string, array{string, class-string}> */
    public static function units(): array
    {
        return [
            'branches' => ['branches', Branch::class],
            'teams' => ['teams', Team::class],
            'job grades' => ['job-grades', JobGrade::class],
            'job levels' => ['job-levels', JobLevel::class],
        ];
    }

    #[DataProvider('units')]
    public function test_it_creates_lists_updates_and_deletes_a_unit(string $uri, string $class): void
    {
        $this->actingAsAdminWithPermissions([
            Permissions::ORGANIZATION_VIEW, Permissions::ORGANIZATION_CREATE,
            Permissions::ORGANIZATION_UPDATE, Permissions::ORGANIZATION_DELETE,
        ]);

        $id = $this->postJson("/api/v1/{$uri}", ['code' => 'A1', 'name' => 'First'])
            ->assertCreated()
            ->assertJsonPath('data.is_active', true)
            ->json('data.id');

        $this->getJson("/api/v1/{$uri}?search=First")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'A1');

        $this->putJson("/api/v1/{$uri}/{$id}", ['name' => 'Renamed', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.code', 'A1')
            ->assertJsonPath('data.is_active', false);

        $this->deleteJson("/api/v1/{$uri}/{$id}")->assertNoContent();
        $this->assertSame(0, $class::query()->count());
    }

    #[DataProvider('units')]
    public function test_codes_are_unique_within_a_list_but_editing_keeps_its_own(string $uri, string $class): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ORGANIZATION_CREATE, Permissions::ORGANIZATION_UPDATE]);
        $existing = $class::factory()->create(['code' => 'DUP']);

        $this->postJson("/api/v1/{$uri}", ['code' => 'DUP', 'name' => 'Again'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->putJson("/api/v1/{$uri}/{$existing->id}", ['code' => 'DUP', 'name' => 'Same code'])->assertOk();
    }

    #[DataProvider('units')]
    public function test_viewing_alone_cannot_change_anything(string $uri, string $class): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ORGANIZATION_VIEW]);
        $unit = $class::factory()->create();

        $this->getJson("/api/v1/{$uri}")->assertOk();
        $this->postJson("/api/v1/{$uri}", ['code' => 'X', 'name' => 'X'])->assertForbidden();
        $this->putJson("/api/v1/{$uri}/{$unit->id}", ['name' => 'X'])->assertForbidden();
        $this->deleteJson("/api/v1/{$uri}/{$unit->id}")->assertForbidden();
    }

    #[DataProvider('units')]
    public function test_it_is_hidden_without_the_view_permission(string $uri, string $class): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->getJson("/api/v1/{$uri}")->assertForbidden();
    }

    public function test_only_a_branch_carries_phone_and_address(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ORGANIZATION_CREATE]);

        $this->postJson('/api/v1/branches', ['code' => 'MAIN', 'name' => 'Main Campus', 'phone' => '012 345 678', 'address' => 'Phnom Penh'])
            ->assertCreated()
            ->assertJsonPath('data.phone', '012 345 678')
            ->assertJsonPath('data.address', 'Phnom Penh');

        $this->postJson('/api/v1/teams', ['code' => 'FD', 'name' => 'Front Desk', 'phone' => '012'])
            ->assertCreated()
            ->assertJsonMissingPath('data.phone');
    }

    public function test_departments_open_to_the_organization_permissions(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ORGANIZATION_VIEW, Permissions::ORGANIZATION_CREATE]);

        $this->postJson('/api/v1/departments', ['code' => 'HR', 'name' => 'Human Resources'])->assertCreated();
        $this->getJson('/api/v1/departments')->assertOk()->assertJsonPath('data.0.code', 'HR');
    }

    public function test_departments_still_open_to_the_assets_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_VIEW]);

        $this->getJson('/api/v1/departments')->assertOk();
    }

    public function test_departments_are_hidden_without_either_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/v1/departments')->assertForbidden();
    }

    public function test_the_chart_lists_active_units_and_working_staff_with_their_placement(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ORGANIZATION_VIEW]);
        $branch = Branch::factory()->create();
        Branch::factory()->create(['is_active' => false]);
        $team = Team::factory()->create();
        $boss = Staff::factory()->create();
        $member = Staff::factory()->create(['branch_id' => $branch->id, 'team_id' => $team->id, 'reports_to_staff_id' => $boss->id]);
        Staff::factory()->create(['status' => Staff::STATUS_RESIGNED]);

        $response = $this->getJson('/api/v1/organization/chart')
            ->assertOk()
            ->assertJsonCount(1, 'data.branches')
            ->assertJsonCount(1, 'data.teams')
            ->assertJsonCount(2, 'data.staff');

        $row = collect($response->json('data.staff'))->firstWhere('id', $member->id);
        $this->assertSame($branch->id, $row['branch_id']);
        $this->assertSame($team->id, $row['team_id']);
        $this->assertSame($boss->id, $row['reports_to_staff_id']);
    }

    public function test_the_chart_needs_the_view_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/v1/organization/chart')->assertForbidden();
    }
}
