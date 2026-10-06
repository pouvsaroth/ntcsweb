<?php

declare(strict_types=1);

namespace Tests\Feature\Recruitment;

use App\Models\JobPosition;
use App\Models\JobPosting;
use App\Models\ManpowerRequest;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * HRM > Recruitment > Job positions / Job postings, and the public Careers
 * page they feed — see JobPositionController and CareerController.
 */
class JobPositionTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const ALL = [Permissions::RECRUITMENT_VIEW, Permissions::RECRUITMENT_CREATE, Permissions::RECRUITMENT_UPDATE, Permissions::RECRUITMENT_DELETE];

    public function test_a_job_opens_from_an_approved_manpower_request_only(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $approved = ManpowerRequest::factory()->create(['status' => ManpowerRequest::STATUS_APPROVED]);
        $pending = ManpowerRequest::factory()->create();

        $this->postJson('/api/v1/job-positions', [
            'manpower_request_id' => $approved->id, 'title' => 'English Teacher', 'headcount' => 2, 'employment_type' => 'full_time',
            'salary_min' => 400, 'salary_max' => 600,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.manpower_request', 'MP-'.str_pad((string) $approved->id, 6, '0', STR_PAD_LEFT))
            ->assertJsonPath('data.opened_on', now()->toDateString());

        $this->postJson('/api/v1/job-positions', ['manpower_request_id' => $pending->id, 'title' => 'X', 'headcount' => 1, 'employment_type' => 'full_time'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('manpower_request_id');

        $this->getJson('/api/v1/manpower-requests')->assertOk();
        $this->assertSame(1, $approved->jobPositions()->count());
    }

    public function test_the_salary_range_and_dates_must_make_sense(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);

        $this->postJson('/api/v1/job-positions', [
            'title' => 'X', 'headcount' => 1, 'employment_type' => 'full_time',
            'salary_min' => 600, 'salary_max' => 400, 'opened_on' => '2026-10-10', 'closes_on' => '2026-10-01',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['salary_max', 'closes_on']);
    }

    public function test_a_job_with_postings_is_closed_not_deleted(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $posted = JobPosting::factory()->create()->jobPosition;
        $unposted = JobPosition::factory()->create();

        $this->deleteJson("/api/v1/job-positions/{$posted->id}")->assertUnprocessable();
        $this->deleteJson("/api/v1/job-positions/{$unposted->id}")->assertNoContent();
    }

    public function test_postings_are_recorded_per_channel(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $job = JobPosition::factory()->create(['title' => 'Accountant']);

        $this->postJson('/api/v1/job-postings', [
            'job_position_id' => $job->id, 'channel' => 'facebook', 'url' => 'https://facebook.com/school/posts/1', 'posted_on' => '2026-10-01',
        ])
            ->assertCreated()
            ->assertJsonPath('data.job_position.title', 'Accountant')
            ->assertJsonPath('data.is_active', true);

        $this->postJson('/api/v1/job-postings', ['job_position_id' => $job->id, 'channel' => 'fax', 'posted_on' => '2026-10-01', 'url' => 'not a url'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['channel', 'url']);
    }

    public function test_the_careers_page_lists_open_jobs_with_a_live_website_posting_only(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $listed = JobPosting::factory()->create()->jobPosition;
        $listed->update(['description' => 'Teach grade 5', 'salary_min' => 500]);
        JobPosting::factory()->create(['channel' => 'facebook']);
        JobPosting::factory()->create(['is_active' => false]);
        JobPosting::factory()->create(['expires_on' => now()->subDay()->toDateString(), 'posted_on' => now()->subWeek()->toDateString()]);
        JobPosting::factory()->create(['posted_on' => now()->addDay()->toDateString()]);
        JobPosting::factory()->create()->jobPosition->update(['status' => JobPosition::STATUS_CLOSED]);

        $this->getJson('/api/v1/public/careers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $listed->id)
            ->assertJsonPath('data.0.salary_min', 500)
            ->assertJsonMissingPath('data.0.description');

        $this->getJson("/api/v1/public/careers/{$listed->id}")->assertOk()->assertJsonPath('data.description', 'Teach grade 5');
        $closedId = JobPosition::query()->where('status', JobPosition::STATUS_CLOSED)->value('id');
        $this->getJson("/api/v1/public/careers/{$closedId}")->assertNotFound();
    }

    public function test_it_needs_the_recruitment_permissions(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::RECRUITMENT_VIEW]);

        $this->getJson('/api/v1/job-positions')->assertOk();
        $this->getJson('/api/v1/job-postings')->assertOk();
        $this->postJson('/api/v1/job-positions', ['title' => 'X', 'headcount' => 1, 'employment_type' => 'full_time'])->assertForbidden();
    }
}
