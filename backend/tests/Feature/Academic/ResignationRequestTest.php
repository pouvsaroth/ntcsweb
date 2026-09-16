<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Permission;
use App\Models\Position;
use App\Models\ResignationRequest;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class ResignationRequestTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private function staffWithUser(): array
    {
        $user = User::factory()->forTenant($this->tenant)->create();
        $staff = Staff::factory()->withUser($user)->create([
            'first_name' => 'Sokha',
            'last_name' => 'Chan',
            'gender' => 'Female',
        ]);

        return [$staff, $user];
    }

    public function test_a_staff_member_can_submit_a_resignation_request_and_it_starts_pending(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$staff, $user] = $this->staffWithUser();
        $this->actingAsTenantUser($user);

        $response = $this->postJson('/api/v1/my-resignation-requests', [
            'resignation_date' => now()->addWeeks(2)->toDateString(),
            'reason' => 'Relocating to another city',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', ResignationRequest::STATUS_PENDING);
        $this->assertSame(1, ResignationRequest::where('staff_id', $staff->id)->count());
    }

    public function test_submitting_a_resignation_request_requires_a_linked_staff_record(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $user = User::factory()->forTenant($this->tenant)->create();
        $this->actingAsTenantUser($user);

        $this->postJson('/api/v1/my-resignation-requests', [
            'resignation_date' => now()->addWeek()->toDateString(),
            'reason' => 'No staff record',
        ])->assertForbidden();
    }

    public function test_the_profile_endpoint_returns_the_staff_members_own_identity_fields(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $position = Position::factory()->create(['name' => 'Registrar']);
        [, $user] = $this->staffWithUser();
        Staff::where('user_id', $user->id)->update(['position_id' => $position->id]);
        $this->actingAsTenantUser($user);

        $response = $this->getJson('/api/v1/my-resignation-requests/profile');

        $response->assertOk();
        $response->assertJsonPath('data.first_name', 'Sokha');
        $response->assertJsonPath('data.last_name', 'Chan');
        $response->assertJsonPath('data.gender', 'Female');
        $response->assertJsonPath('data.position', 'Registrar');
    }

    public function test_approving_a_resignation_request_marks_it_decided(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::RESIGNATION_REQUESTS_APPROVE]);
        [$staff] = $this->staffWithUser();
        $resignationRequest = ResignationRequest::factory()->forStaff($staff)->create();

        $response = $this->postJson("/api/v1/resignation-requests/{$resignationRequest->id}/approve");

        $response->assertOk();
        $response->assertJsonPath('data.status', ResignationRequest::STATUS_APPROVED);
        $this->assertSame(ResignationRequest::STATUS_APPROVED, $resignationRequest->fresh()->status);
        $this->assertSame($admin->id, $resignationRequest->fresh()->decided_by);
    }

    public function test_approving_a_resignation_request_requires_the_approve_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$staff] = $this->staffWithUser();
        $resignationRequest = ResignationRequest::factory()->forStaff($staff)->create();

        $this->postJson("/api/v1/resignation-requests/{$resignationRequest->id}/approve")->assertForbidden();
    }

    public function test_approving_an_already_decided_resignation_request_fails(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::RESIGNATION_REQUESTS_APPROVE]);
        [$staff] = $this->staffWithUser();
        $resignationRequest = ResignationRequest::factory()->forStaff($staff)->approved()->create();

        $this->postJson("/api/v1/resignation-requests/{$resignationRequest->id}/approve")->assertUnprocessable();
    }

    public function test_rejecting_a_resignation_request_records_a_reason(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::RESIGNATION_REQUESTS_REJECT]);
        [$staff] = $this->staffWithUser();
        $resignationRequest = ResignationRequest::factory()->forStaff($staff)->create();

        $response = $this->postJson("/api/v1/resignation-requests/{$resignationRequest->id}/reject", ['reason' => 'Needs more notice']);

        $response->assertOk();
        $response->assertJsonPath('data.status', ResignationRequest::STATUS_REJECTED);
        $response->assertJsonPath('data.decision_reason', 'Needs more notice');
        $this->assertSame($admin->id, $resignationRequest->fresh()->decided_by);
    }

    public function test_a_staff_member_sees_only_their_own_resignation_requests(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$staff, $user] = $this->staffWithUser();
        [$otherStaff] = $this->staffWithUser();

        ResignationRequest::factory()->forStaff($staff)->create(['reason' => 'Mine']);
        ResignationRequest::factory()->forStaff($otherStaff)->create(['reason' => 'Not mine']);

        $this->actingAsTenantUser($user);
        $response = $this->getJson('/api/v1/my-resignation-requests')->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.reason', 'Mine');
    }

    public function test_viewing_all_resignation_requests_requires_the_view_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/v1/resignation-requests')->assertForbidden();
    }

    public function test_submitting_a_resignation_request_notifies_every_holder_of_the_approve_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [, $staffUser] = $this->staffWithUser();

        $approverRole = Role::factory()->forTenant($this->tenant)->create(['slug' => 'test-hr-approver', 'level' => 50]);
        $approverRole->permissions()->attach(Permission::query()->where('slug', Permissions::RESIGNATION_REQUESTS_APPROVE)->firstOrFail());
        $approver = User::factory()->forTenant($this->tenant)->create();
        $approver->attachRoles($approverRole);

        // No approve permission — must not be notified.
        $bystander = User::factory()->forTenant($this->tenant)->create();

        $this->actingAsTenantUser($staffUser);
        $this->postJson('/api/v1/my-resignation-requests', [
            'resignation_date' => now()->addWeek()->toDateString(),
            'reason' => 'Career change',
        ])->assertCreated();

        $this->assertSame(
            1,
            UserNotification::where('recipient_id', $approver->id)->where('type', NotificationType::RESIGNATION_REQUEST_SUBMITTED)->count(),
        );
        $this->assertSame(0, UserNotification::where('recipient_id', $bystander->id)->count());
    }

    public function test_approving_a_resignation_request_notifies_the_staff_member(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::RESIGNATION_REQUESTS_APPROVE]);
        [$staff, $staffUser] = $this->staffWithUser();

        $resignationRequest = ResignationRequest::factory()->forStaff($staff)->create();

        $this->postJson("/api/v1/resignation-requests/{$resignationRequest->id}/approve")->assertOk();

        $this->assertSame(
            1,
            UserNotification::where('recipient_id', $staffUser->id)->where('type', NotificationType::RESIGNATION_REQUEST_APPROVED)->count(),
        );
    }
}
