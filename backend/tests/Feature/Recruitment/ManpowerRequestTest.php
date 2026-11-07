<?php

declare(strict_types=1);

namespace Tests\Feature\Recruitment;

use App\Models\Department;
use App\Models\ManpowerRequest;
use App\Models\UserNotification;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * HRM > Recruitment > Manpower request — see ManpowerRequestController.
 */
class ManpowerRequestTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return [
            'job_title' => 'English Teacher',
            'headcount' => 2,
            'employment_type' => 'full_time',
            'needed_by' => now()->addMonth()->toDateString(),
            'reason' => 'Two new classes start next term.',
            ...$overrides,
        ];
    }

    public function test_submitting_puts_it_in_the_approval_queue_and_notifies_approvers(): void
    {
        $admin = $this->actingAsAdminWithPermissions([
            Permissions::RECRUITMENT_VIEW, Permissions::MANPOWER_REQUESTS_CREATE, Permissions::JOB_POSITIONS_CREATE, Permissions::JOB_POSTINGS_CREATE, Permissions::APPLICANTS_CREATE, Permissions::APPLICANT_DOCUMENTS_CREATE, Permissions::INTERVIEWS_CREATE, Permissions::OFFER_LETTERS_CREATE, Permissions::MANPOWER_REQUESTS_APPROVE,
        ]);
        $department = Department::factory()->create(['name' => 'Academic']);

        $response = $this->postJson('/api/v1/manpower-requests', $this->payload(['department_id' => $department->id]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.department', 'Academic');
        $this->assertSame('MP-'.str_pad((string) $response->json('data.id'), 6, '0', STR_PAD_LEFT), $response->json('data.reference'));

        $this->getJson('/api/v1/manpower-requests?approval_queue=1')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(1, UserNotification::query()->where('recipient_id', $admin->id)->where('type', NotificationType::MANPOWER_REQUEST_SUBMITTED)->count());
    }

    public function test_approving_and_rejecting_decide_it_once(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::MANPOWER_REQUESTS_APPROVE, Permissions::MANPOWER_REQUESTS_REJECT]);
        $approved = ManpowerRequest::factory()->create();
        $rejected = ManpowerRequest::factory()->create();

        $this->postJson("/api/v1/manpower-requests/{$approved->id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/v1/manpower-requests/{$approved->id}/approve")->assertUnprocessable();

        $this->postJson("/api/v1/manpower-requests/{$rejected->id}/reject", ['reason' => 'No budget'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.decision_reason', 'No budget');
    }

    public function test_only_a_pending_request_can_be_edited_and_an_approved_one_never_deleted(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::MANPOWER_REQUESTS_UPDATE, Permissions::JOB_POSITIONS_UPDATE, Permissions::JOB_POSTINGS_UPDATE, Permissions::APPLICANTS_UPDATE, Permissions::INTERVIEWS_UPDATE, Permissions::INTERVIEW_EVALUATIONS_CREATE, Permissions::OFFER_LETTERS_UPDATE, Permissions::MANPOWER_REQUESTS_DELETE, Permissions::JOB_POSITIONS_DELETE, Permissions::JOB_POSTINGS_DELETE, Permissions::APPLICANTS_DELETE, Permissions::APPLICANT_DOCUMENTS_DELETE, Permissions::INTERVIEWS_DELETE, Permissions::INTERVIEW_EVALUATIONS_DELETE, Permissions::OFFER_LETTERS_DELETE]);
        $pending = ManpowerRequest::factory()->create();
        $approved = ManpowerRequest::factory()->create(['status' => ManpowerRequest::STATUS_APPROVED]);

        $this->putJson("/api/v1/manpower-requests/{$pending->id}", ['headcount' => 4])->assertOk()->assertJsonPath('data.headcount', 4);
        $this->putJson("/api/v1/manpower-requests/{$approved->id}", ['headcount' => 4])->assertForbidden();
        $this->deleteJson("/api/v1/manpower-requests/{$approved->id}")->assertForbidden();
        $this->deleteJson("/api/v1/manpower-requests/{$pending->id}")->assertNoContent();
    }

    public function test_it_needs_the_recruitment_and_decision_permissions(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::RECRUITMENT_VIEW]);
        $request = ManpowerRequest::factory()->create();

        $this->getJson('/api/v1/manpower-requests')->assertOk();
        $this->postJson('/api/v1/manpower-requests', $this->payload())->assertForbidden();
        $this->postJson("/api/v1/manpower-requests/{$request->id}/approve")->assertForbidden();
        $this->postJson("/api/v1/manpower-requests/{$request->id}/reject", ['reason' => 'x'])->assertForbidden();
    }

    public function test_the_job_and_reason_are_required(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::MANPOWER_REQUESTS_CREATE, Permissions::JOB_POSITIONS_CREATE, Permissions::JOB_POSTINGS_CREATE, Permissions::APPLICANTS_CREATE, Permissions::APPLICANT_DOCUMENTS_CREATE, Permissions::INTERVIEWS_CREATE, Permissions::OFFER_LETTERS_CREATE]);

        $this->postJson('/api/v1/manpower-requests', ['employment_type' => 'freelance'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['job_title', 'headcount', 'employment_type', 'reason']);
    }
}
