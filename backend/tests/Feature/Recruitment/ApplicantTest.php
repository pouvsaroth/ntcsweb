<?php

declare(strict_types=1);

namespace Tests\Feature\Recruitment;

use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\JobPosition;
use App\Models\JobPosting;
use App\Models\UserNotification;
use App\Services\Recruitment\ApplicantService;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * HRM > Recruitment > Applicant management / CV/resume, and the public
 * Careers page's Apply form — see ApplicantController, ApplicantDocumentController
 * and CareerController::apply().
 */
class ApplicantTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const ALL = [Permissions::RECRUITMENT_VIEW, Permissions::RECRUITMENT_CREATE, Permissions::RECRUITMENT_UPDATE, Permissions::RECRUITMENT_DELETE];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function liveJob(): JobPosition
    {
        return JobPosting::factory()->create()->jobPosition;
    }

    private function application(array $overrides = []): array
    {
        return [
            'first_name' => 'Sokha',
            'last_name' => 'Chan',
            'phone' => '012 345 678',
            'email' => 'sokha@example.com',
            'cv' => UploadedFile::fake()->create('Sokha CV.pdf', 300, 'application/pdf'),
            ...$overrides,
        ];
    }

    public function test_applying_on_the_careers_page_creates_an_applicant_with_a_private_cv_and_notifies_hr(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::RECRUITMENT_VIEW]);
        $job = $this->liveJob();

        $this->post("/api/v1/public/careers/{$job->id}/apply", $this->application(), ['Accept' => 'application/json'])->assertCreated();

        $applicant = Applicant::query()->sole();
        $this->assertSame('website', $applicant->source);
        $this->assertSame('new', $applicant->stage);
        $this->assertSame($job->id, $applicant->job_position_id);

        $document = $applicant->documents()->sole();
        $this->assertSame('cv', $document->type);
        $this->assertSame('Sokha CV.pdf', $document->original_name);
        $this->assertNull($document->uploaded_by);
        Storage::disk('local')->assertExists($document->file_path);
        $this->assertStringContainsString("tenants/{$this->tenant->id}/recruitment/applicants/", $document->file_path);

        $this->assertSame(1, UserNotification::query()->where('recipient_id', $admin->id)->where('type', NotificationType::JOB_APPLICATION_RECEIVED)->count());
    }

    public function test_the_same_phone_cannot_apply_twice_for_one_job(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $job = $this->liveJob();

        $this->post("/api/v1/public/careers/{$job->id}/apply", $this->application(), ['Accept' => 'application/json'])->assertCreated();
        $this->post("/api/v1/public/careers/{$job->id}/apply", $this->application(['phone' => '012-345-678']), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        $this->assertSame(1, Applicant::query()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_a_cv_is_required_and_only_document_types_are_accepted(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $job = $this->liveJob();

        $this->post("/api/v1/public/careers/{$job->id}/apply", $this->application(['cv' => null]), ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('cv');
        $this->post("/api/v1/public/careers/{$job->id}/apply", $this->application(['cv' => UploadedFile::fake()->create('run.exe', 10)]), ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('cv');
    }

    public function test_a_job_not_on_the_careers_page_cannot_be_applied_for(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $closed = $this->liveJob();
        $closed->update(['status' => JobPosition::STATUS_CLOSED]);

        $this->post("/api/v1/public/careers/{$closed->id}/apply", $this->application(), ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_hr_adds_moves_and_removes_an_applicant_with_their_files(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $job = JobPosition::factory()->create();

        $id = $this->post('/api/v1/applicants', [
            'job_position_id' => $job->id, 'first_name' => 'Dara', 'last_name' => 'Kim', 'phone' => '098765432', 'source' => 'referral',
            'cv' => UploadedFile::fake()->create('cv.docx', 100),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.documents.0.type', 'cv')
            ->json('data.id');

        $this->post('/api/v1/applicant-documents', [
            'applicant_id' => $id, 'type' => 'certificate', 'file' => UploadedFile::fake()->image('degree.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->putJson("/api/v1/applicants/{$id}", ['stage' => 'shortlisted', 'notes' => 'Strong English'])
            ->assertOk()
            ->assertJsonPath('data.stage', 'shortlisted');

        $this->getJson('/api/v1/applicant-documents?filter[type]=certificate')->assertOk()->assertJsonCount(1, 'data');
        $this->assertCount(2, Storage::disk('local')->allFiles());

        $this->deleteJson("/api/v1/applicants/{$id}")->assertNoContent();
        $this->assertSame(0, ApplicantDocument::query()->count());
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_a_cv_downloads_only_for_recruitment_staff(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::RECRUITMENT_VIEW]);
        $applicant = Applicant::factory()->create();
        $document = app(ApplicantService::class)->attach($applicant, UploadedFile::fake()->create('cv.pdf', 50, 'application/pdf'), 'cv', null);

        $this->get("/api/v1/applicant-documents/{$document->id}/download")->assertOk()->assertDownload('cv.pdf');

        $this->actingAsAdminWithPermissions([]);
        $this->get("/api/v1/applicant-documents/{$document->id}/download", ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_moving_an_applicant_along_the_pipeline_cannot_skip_hiring(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $applicant = Applicant::factory()->create(['stage' => 'offer']);

        $this->putJson("/api/v1/applicants/{$applicant->id}", ['stage' => 'screening'])->assertOk()->assertJsonPath('data.stage', 'screening');
        $this->putJson("/api/v1/applicants/{$applicant->id}", ['stage' => 'hired'])->assertUnprocessable();
        $this->assertSame('screening', $applicant->fresh()->stage);
    }
}
