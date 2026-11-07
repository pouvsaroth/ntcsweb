<?php

declare(strict_types=1);

namespace Tests\Feature\Recruitment;

use App\Models\JobPosition;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * Roles step 5b: Recruitment's tabs each have their own Add / Update /
 * Delete instead of one recruitment.create|update|delete.
 */
class RecruitmentPermissionsSplitTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_job_positions_add_update_and_delete_are_their_own(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::RECRUITMENT_VIEW, Permissions::JOB_POSTINGS_CREATE]);
        $job = JobPosition::factory()->create();
        $this->putJson("/api/v1/job-positions/{$job->id}", ['title' => 'Teacher'])->assertForbidden();
        $this->deleteJson("/api/v1/job-positions/{$job->id}")->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::RECRUITMENT_VIEW, Permissions::JOB_POSITIONS_UPDATE]);
        $this->assertNotSame(403, $this->putJson("/api/v1/job-positions/{$job->id}", ['title' => 'Teacher'])->status());
        $this->deleteJson("/api/v1/job-positions/{$job->id}")->assertForbidden();
    }

    public function test_view_alone_changes_nothing(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::RECRUITMENT_VIEW]);
        $job = JobPosition::factory()->create();
        $this->getJson('/api/v1/job-positions')->assertOk();
        $this->putJson("/api/v1/job-positions/{$job->id}", ['title' => 'Teacher'])->assertForbidden();
        $this->deleteJson("/api/v1/job-positions/{$job->id}")->assertForbidden();
    }

    public function test_school_admin_has_every_new_permission_by_default(): void
    {
        $defaults = Permissions::defaultsForSystemRoles()['school-admin'];
        foreach ([Permissions::MANPOWER_REQUESTS_DELETE, Permissions::JOB_POSITIONS_DELETE, Permissions::JOB_POSTINGS_DELETE, Permissions::APPLICANTS_DELETE,
            Permissions::APPLICANT_DOCUMENTS_DELETE, Permissions::INTERVIEWS_DELETE, Permissions::INTERVIEW_EVALUATIONS_DELETE, Permissions::OFFER_LETTERS_DELETE] as $slug) {
            $this->assertContains($slug, $defaults);
        }
    }
}
