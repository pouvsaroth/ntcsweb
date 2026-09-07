<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Models\ApprovalRequest;
use App\Models\FormCategory;
use App\Models\FormTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class ApprovalRequestTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_any_authenticated_user_can_browse_the_form_catalog(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $category = FormCategory::factory()->create();
        FormTemplate::factory()->create(['form_category_id' => $category->id]);

        $this->getJson('/api/v1/form-categories')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/form-templates')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_creating_a_form_category_requires_the_manage_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->postJson('/api/v1/form-categories', ['name' => 'Finance'])->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::FORM_CATEGORIES_MANAGE]);
        $response = $this->postJson('/api/v1/form-categories', ['name' => 'Finance']);

        $response->assertCreated();
        $this->assertDatabaseHas('form_categories', ['name' => 'Finance', 'tenant_id' => $this->tenant->id]);
    }

    public function test_creating_a_form_template_requires_the_manage_permission_and_a_valid_category(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::FORM_TEMPLATES_MANAGE]);
        $category = FormCategory::factory()->create();

        $response = $this->postJson('/api/v1/form-templates', [
            'form_category_id' => $category->id,
            'code' => 'TT-OTR-FM-001',
            'name' => 'Office Transfer Request',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('form_templates', ['code' => 'TT-OTR-FM-001', 'tenant_id' => $this->tenant->id]);
    }

    public function test_any_authenticated_user_can_submit_a_request_against_an_active_template(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $template = FormTemplate::factory()->create();
        $user = User::factory()->forTenant($this->tenant)->create();
        $this->actingAsTenantUser($user);

        $response = $this->postJson('/api/v1/my-approval-requests', [
            'form_template_id' => $template->id,
            'subject' => 'Requesting office transfer',
            'details' => 'Please move me to the downtown branch.',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', ApprovalRequest::STATUS_PENDING);
        $this->assertSame(1, ApprovalRequest::where('requested_by', $user->id)->count());
    }

    public function test_a_user_sees_only_their_own_approval_requests(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $template = FormTemplate::factory()->create();
        $user = User::factory()->forTenant($this->tenant)->create();
        $otherUser = User::factory()->forTenant($this->tenant)->create();

        ApprovalRequest::factory()->create(['form_template_id' => $template->id, 'requested_by' => $user->id, 'subject' => 'Mine']);
        ApprovalRequest::factory()->create(['form_template_id' => $template->id, 'requested_by' => $otherUser->id, 'subject' => 'Not mine']);

        $this->actingAsTenantUser($user);
        $response = $this->getJson('/api/v1/my-approval-requests')->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.subject', 'Mine');
    }

    public function test_viewing_the_approval_queue_requires_the_view_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/v1/approval-requests')->assertForbidden();
    }

    public function test_approving_a_request_requires_the_approve_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $approvalRequest = ApprovalRequest::factory()->create();

        $this->postJson("/api/v1/approval-requests/{$approvalRequest->id}/approve")->assertForbidden();
    }

    public function test_approving_a_pending_request_marks_it_decided(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::APPROVAL_REQUESTS_APPROVE]);
        $approvalRequest = ApprovalRequest::factory()->create();

        $response = $this->postJson("/api/v1/approval-requests/{$approvalRequest->id}/approve");

        $response->assertOk();
        $response->assertJsonPath('data.status', ApprovalRequest::STATUS_APPROVED);
        $this->assertSame($admin->id, $approvalRequest->fresh()->decided_by);
    }

    public function test_approving_an_already_decided_request_fails(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::APPROVAL_REQUESTS_APPROVE]);
        $approvalRequest = ApprovalRequest::factory()->create(['status' => ApprovalRequest::STATUS_REJECTED]);

        $this->postJson("/api/v1/approval-requests/{$approvalRequest->id}/approve")->assertUnprocessable();
    }

    public function test_rejecting_a_request_records_a_reason(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::APPROVAL_REQUESTS_REJECT]);
        $approvalRequest = ApprovalRequest::factory()->create();

        $response = $this->postJson("/api/v1/approval-requests/{$approvalRequest->id}/reject", ['reason' => 'Incomplete information']);

        $response->assertOk();
        $response->assertJsonPath('data.status', ApprovalRequest::STATUS_REJECTED);
        $response->assertJsonPath('data.decision_reason', 'Incomplete information');
    }

    public function test_an_approval_request_from_another_tenant_cannot_be_fetched_directly(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::APPROVAL_REQUESTS_VIEW]);
        $other = $this->createForOtherTenant(function () {
            $tenant = Tenant::factory()->create();
            $category = FormCategory::factory()->forTenant($tenant)->create();
            $template = FormTemplate::factory()->forTenant($tenant)->create(['form_category_id' => $category->id]);
            $user = User::factory()->forTenant($tenant)->create();

            return ApprovalRequest::factory()->forTenant($tenant)->create([
                'form_template_id' => $template->id,
                'requested_by' => $user->id,
            ]);
        });

        $this->getJson("/api/v1/approval-requests/{$other->id}")->assertNotFound();
    }
}
