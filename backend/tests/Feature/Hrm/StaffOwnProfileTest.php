<?php

declare(strict_types=1);

namespace Tests\Feature\Hrm;

use App\Models\Position;
use App\Models\Role;
use App\Models\Staff;
use App\Support\Authorization\PermissionRegistry;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** staff.update-own: HRM > Staff shows only the user's own record, editable except work fields. */
class StaffOwnProfileTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private function ownStaff(): Staff
    {
        $staff = Staff::factory()->create(['first_name' => 'Own']);
        $staff->forceFill(['user_id' => $this->admin->id])->save();

        return $staff->refresh();
    }

    public function test_the_list_shows_only_their_own_record_even_with_staff_view(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_VIEW, Permissions::STAFF_UPDATE_OWN]);
        $own = $this->ownStaff();
        $other = Staff::factory()->create();

        $this->getJson('/api/v1/staff')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->getJson("/api/v1/staff/{$own->id}")->assertOk();
        $this->getJson("/api/v1/staff/{$other->id}")->assertForbidden();
        $this->getJson("/api/v1/staff/{$other->id}/records/family-members")->assertForbidden();
    }

    public function test_they_edit_their_own_profile_and_tabs_but_not_work_fields_or_others(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_UPDATE_OWN]);
        $own = $this->ownStaff();
        $other = Staff::factory()->create();
        $newPosition = Position::factory()->create();
        $workFields = fn (Staff $staff) => [...$staff->only(['position_id', 'status', 'branch_id', 'reports_to_staff_id']), 'hire_date' => $staff->hire_date?->toDateString()];
        $before = $workFields($own);

        $this->putJson("/api/v1/staff/{$own->id}", [
            'first_name' => 'Renamed', 'phone' => '012 000 111',
            'position_id' => $newPosition->id, 'hire_date' => '2001-01-01', 'status' => 'suspended', 'reports_to_staff_id' => $other->id,
        ])->assertOk()->assertJsonPath('data.first_name', 'Renamed');

        $own->refresh();
        $this->assertSame('012 000 111', $own->phone);
        $this->assertSame($before, $workFields($own));

        $base = "/api/v1/staff/{$own->id}/records";
        $this->postJson("{$base}/emergency-contacts", ['name' => 'Sok Dara', 'relationship' => 'mother', 'phone' => '012 345 678'])->assertCreated();
        $this->postJson("{$base}/languages", ['language' => 'English', 'level' => 'fluent'])->assertCreated();
        $this->postJson("/api/v1/staff/{$own->id}/educations", [])->assertUnprocessable(); // reaches validation, so allowed

        $this->putJson("/api/v1/staff/{$other->id}", ['first_name' => 'Hacked'])->assertForbidden();
        $this->postJson("/api/v1/staff/{$other->id}/records/languages", ['language' => 'English', 'level' => 'fluent'])->assertForbidden();
        $this->deleteJson("/api/v1/staff/{$own->id}")->assertForbidden();
    }

    public function test_own_profile_only_wins_over_staff_view_and_update(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_VIEW, Permissions::STAFF_UPDATE, Permissions::STAFF_UPDATE_OWN]);
        $own = $this->ownStaff();
        $other = Staff::factory()->create();

        $this->getJson('/api/v1/staff')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->getJson("/api/v1/staff/{$other->id}")->assertForbidden();
        $this->deleteJson("/api/v1/staff/{$other->id}")->assertForbidden();
    }

    public function test_school_admin_is_never_limited_to_their_own_profile(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_VIEW, Permissions::STAFF_UPDATE, Permissions::STAFF_UPDATE_OWN]);
        $schoolAdmin = Role::query()->where('tenant_id', $this->tenant->id)->where('slug', Role::SCHOOL_ADMIN)->first()
            ?? Role::factory()->forTenant($this->tenant)->create(['name' => 'School Admin', 'slug' => Role::SCHOOL_ADMIN]);
        $this->admin->attachRoles($schoolAdmin);
        app(PermissionRegistry::class)->invalidate($this->tenant->id);
        $this->actingAsTenantUser($this->admin->fresh());
        $this->ownStaff();
        Staff::factory()->create();

        $this->getJson('/api/v1/staff')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_hr_with_staff_update_still_sees_and_edits_everyone(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_VIEW, Permissions::STAFF_UPDATE]);
        $this->ownStaff();
        $other = Staff::factory()->create();
        $position = Position::factory()->create();

        $this->getJson('/api/v1/staff')->assertOk()->assertJsonCount(2, 'data');
        $this->putJson("/api/v1/staff/{$other->id}", ['position_id' => $position->id])->assertOk();
        $this->assertSame($position->id, $other->refresh()->position_id);
    }

    public function test_staff_editors_can_list_positions_for_the_form_without_the_positions_menu(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_VIEW, Permissions::STAFF_UPDATE]);
        Position::factory()->create();

        $this->getJson('/api/v1/positions')->assertOk();
        $this->getJson('/api/v1/staff')->assertOk();
    }

    public function test_view_only_staff_users_cannot_list_positions(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_VIEW]);

        $this->getJson('/api/v1/positions')->assertForbidden();
    }
}
