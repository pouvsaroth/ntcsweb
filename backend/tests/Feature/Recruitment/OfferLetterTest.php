<?php

declare(strict_types=1);

namespace Tests\Feature\Recruitment;

use App\Models\Applicant;
use App\Models\Interview;
use App\Models\InterviewEvaluation;
use App\Models\JobPosition;
use App\Models\OfferLetter;
use App\Models\Staff;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * HRM > Recruitment > Candidate selection / Offer letter / Hire — see
 * CandidateSelectionController, OfferLetterController and OfferLetterService.
 */
class OfferLetterTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const ALL = [
        Permissions::RECRUITMENT_VIEW, Permissions::RECRUITMENT_CREATE, Permissions::RECRUITMENT_UPDATE, Permissions::RECRUITMENT_DELETE,
        Permissions::STAFF_CREATE,
    ];

    private function evaluate(Applicant $applicant, int $score, string $recommendation): void
    {
        $interview = Interview::factory()->create(['applicant_id' => $applicant->id]);
        InterviewEvaluation::query()->create([
            'interview_id' => $interview->id, 'evaluator_id' => $this->admin->id,
            'scores' => array_fill_keys(InterviewEvaluation::CRITERIA, $score), 'overall_score' => $score, 'recommendation' => $recommendation,
        ]);
    }

    public function test_candidates_are_ranked_by_their_interview_scores(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $job = JobPosition::factory()->create();
        $good = Applicant::factory()->create(['job_position_id' => $job->id, 'first_name' => 'Good']);
        $weak = Applicant::factory()->create(['job_position_id' => $job->id, 'first_name' => 'Weak']);
        $fresh = Applicant::factory()->create(['job_position_id' => $job->id, 'first_name' => 'Fresh']);
        Applicant::factory()->create(['job_position_id' => $job->id, 'stage' => 'withdrawn']);
        $this->evaluate($good, 5, 'hire');
        $this->evaluate($weak, 2, 'no_hire');

        $this->getJson("/api/v1/candidate-selection?job_position_id={$job->id}")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $good->id)
            ->assertJsonPath('data.0.average_score', 5)
            ->assertJsonPath('data.0.votes.hire', 1)
            ->assertJsonPath('data.1.id', $weak->id)
            ->assertJsonPath('data.2.id', $fresh->id)
            ->assertJsonPath('data.2.average_score', null);
    }

    public function test_an_offer_moves_the_applicant_to_offer_and_a_declined_one_withdraws_them(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $applicant = Applicant::factory()->create(['stage' => 'interview']);

        $id = $this->postJson('/api/v1/offer-letters', [
            'applicant_id' => $applicant->id, 'position_title' => 'English Teacher', 'employment_type' => 'full_time',
            'salary' => 650, 'start_date' => now()->addMonth()->toDateString(), 'probation_months' => 3,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.applicant.stage', 'offer')
            ->json('data.id');

        $this->putJson("/api/v1/offer-letters/{$id}", ['status' => 'sent'])->assertOk()->assertJsonPath('data.status', 'sent');
        $this->assertNotNull(OfferLetter::query()->find($id)->sent_at);

        $this->putJson("/api/v1/offer-letters/{$id}", ['status' => 'declined'])->assertOk();
        $this->assertSame('withdrawn', $applicant->fresh()->stage);
        $this->putJson("/api/v1/offer-letters/{$id}", ['salary' => 900])->assertUnprocessable();
    }

    public function test_a_rejected_or_hired_applicant_cannot_be_made_an_offer(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $applicant = Applicant::factory()->create(['stage' => 'rejected']);

        $this->postJson('/api/v1/offer-letters', [
            'applicant_id' => $applicant->id, 'position_title' => 'X', 'employment_type' => 'full_time', 'salary' => 1, 'start_date' => '2026-12-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('applicant_id');
    }

    public function test_hiring_an_accepted_offer_links_the_staff_marks_hired_and_fills_the_job(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $job = JobPosition::factory()->create(['headcount' => 1]);
        $applicant = Applicant::factory()->create(['job_position_id' => $job->id, 'stage' => 'offer']);
        $offer = OfferLetter::factory()->create(['applicant_id' => $applicant->id, 'job_position_id' => $job->id, 'status' => 'accepted']);
        $staff = Staff::factory()->create();

        $this->postJson("/api/v1/offer-letters/{$offer->id}/hire", ['staff_id' => $staff->id])
            ->assertOk()
            ->assertJsonPath('data.hired_staff_id', $staff->id);

        $this->assertSame('hired', $applicant->fresh()->stage);
        $this->assertSame(JobPosition::STATUS_FILLED, $job->fresh()->status);
        $this->postJson("/api/v1/offer-letters/{$offer->id}/hire", ['staff_id' => $staff->id])->assertUnprocessable();
    }

    public function test_only_an_accepted_offer_can_be_hired_and_only_a_draft_deleted(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $sent = OfferLetter::factory()->create(['status' => 'sent']);
        $draft = OfferLetter::factory()->create();

        $this->postJson("/api/v1/offer-letters/{$sent->id}/hire", ['staff_id' => Staff::factory()->create()->id])->assertUnprocessable();
        $this->deleteJson("/api/v1/offer-letters/{$sent->id}")->assertUnprocessable();
        $this->deleteJson("/api/v1/offer-letters/{$draft->id}")->assertNoContent();
    }
}
