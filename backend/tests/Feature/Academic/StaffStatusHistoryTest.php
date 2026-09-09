<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Position;
use App\Models\Role;
use App\Models\Staff;
use App\Models\StaffStatusHistory;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class StaffStatusHistoryTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_changing_status_records_history_and_updates_the_column(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_CREATE, Permissions::STAFF_CHANGE_STATUS]);
        $staff = $this->createStaff();

        $response = $this->postJson("/api/v1/staff/{$staff->id}/status", [
            'status' => Staff::STATUS_ON_LEAVE,
            'reason' => 'Medical leave',
            'requested_date' => '2026-02-01',
            'effective_date' => '2026-02-05',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', Staff::STATUS_ON_LEAVE);

        $history = StaffStatusHistory::where('staff_id', $staff->id)->firstOrFail();
        $this->assertSame(Staff::STATUS_ACTIVE, $history->from_status);
        $this->assertSame(Staff::STATUS_ON_LEAVE, $history->to_status);
        $this->assertSame('Medical leave', $history->reason);
        $this->assertSame('2026-02-01', $history->requested_date->toDateString());
        $this->assertSame('2026-02-05', $history->effective_date->toDateString());
    }

    public function test_changing_status_requires_reason_and_both_dates(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_CREATE, Permissions::STAFF_CHANGE_STATUS]);
        $staff = $this->createStaff();

        $this->postJson("/api/v1/staff/{$staff->id}/status", ['status' => Staff::STATUS_SUSPENDED])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason', 'requested_date', 'effective_date']);
    }

    public function test_requested_date_after_effective_date_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_CREATE, Permissions::STAFF_CHANGE_STATUS]);
        $staff = $this->createStaff();

        $this->postJson("/api/v1/staff/{$staff->id}/status", [
            'status' => Staff::STATUS_SUSPENDED,
            'reason' => 'x',
            'requested_date' => '2026-03-10',
            'effective_date' => '2026-03-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('requested_date');
    }

    public function test_changing_status_requires_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_CREATE]);
        $staff = $this->createStaff();

        $this->postJson("/api/v1/staff/{$staff->id}/status", [
            'status' => Staff::STATUS_RETIRED,
            'reason' => 'x',
            'requested_date' => '2026-01-01',
            'effective_date' => '2026-01-01',
        ])->assertForbidden();
    }

    public function test_staff_can_move_freely_between_any_two_statuses(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_CREATE, Permissions::STAFF_CHANGE_STATUS]);
        $staff = $this->createStaff();

        $this->postJson("/api/v1/staff/{$staff->id}/status", [
            'status' => Staff::STATUS_RETIRED, 'reason' => 'x', 'requested_date' => '2026-01-01', 'effective_date' => '2026-01-01',
        ])->assertOk();

        // No terminal-status lockout — a "retired" staff member can be
        // brought back to active, unlike Enrollment's dropped state.
        $this->postJson("/api/v1/staff/{$staff->id}/status", [
            'status' => Staff::STATUS_ACTIVE, 'reason' => 'Rehired', 'requested_date' => '2026-06-01', 'effective_date' => '2026-06-01',
        ])->assertOk()->assertJsonPath('data.status', Staff::STATUS_ACTIVE);

        $this->assertSame(2, StaffStatusHistory::where('staff_id', $staff->id)->count());
    }

    public function test_the_status_history_page_lists_entries_across_every_staff_member(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_CREATE, Permissions::STAFF_CHANGE_STATUS, Permissions::STAFF_VIEW]);
        $staffA = $this->createStaff();
        $staffB = $this->createStaff();

        $this->postJson("/api/v1/staff/{$staffA->id}/status", [
            'status' => Staff::STATUS_SUSPENDED, 'reason' => 'a', 'requested_date' => '2026-01-01', 'effective_date' => '2026-01-01',
        ])->assertOk();
        $this->postJson("/api/v1/staff/{$staffB->id}/status", [
            'status' => Staff::STATUS_RESIGNED, 'reason' => 'b', 'requested_date' => '2026-01-02', 'effective_date' => '2026-01-02',
        ])->assertOk();

        $response = $this->getJson('/api/v1/staff-status-histories');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    public function test_viewing_the_status_history_page_requires_staff_view_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/v1/staff-status-histories')->assertForbidden();
    }

    private function createStaff(): Staff
    {
        static $counter = 0;
        $counter++;

        $role = Role::factory()->forTenant($this->tenant)->create();
        $position = Position::factory()->create(['role_id' => $role->id]);

        $response = $this->postJson('/api/v1/staff', [
            'first_name' => 'Staff',
            'last_name' => (string) $counter,
            'phone' => '03000'.str_pad((string) $counter, 4, '0', STR_PAD_LEFT),
            'position_id' => $position->id,
        ])->assertCreated();

        return Staff::query()->findOrFail($response->json('data.id'));
    }
}
