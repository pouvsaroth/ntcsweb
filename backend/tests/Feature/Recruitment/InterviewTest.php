<?php

declare(strict_types=1);

namespace Tests\Feature\Recruitment;

use App\Models\Applicant;
use App\Models\Interview;
use App\Models\InterviewEvaluation;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * HRM > Recruitment > Interview / Interview evaluation — see
 * InterviewController and InterviewEvaluationController.
 */
class InterviewTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const ALL = [Permissions::RECRUITMENT_VIEW, Permissions::RECRUITMENT_CREATE, Permissions::RECRUITMENT_UPDATE, Permissions::RECRUITMENT_DELETE];

    /** A working staff member with a login — someone who can sit on an interview. */
    private function interviewer(): User
    {
        $user = User::factory()->forTenant($this->tenant)->create();
        Staff::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    private function scores(int $each = 4): array
    {
        return array_fill_keys(InterviewEvaluation::CRITERIA, $each);
    }

    public function test_scheduling_moves_the_applicant_to_interview_and_notifies_the_interviewers(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $interviewer = $this->interviewer();
        $applicant = Applicant::factory()->create(['stage' => 'shortlisted']);

        $this->postJson('/api/v1/interviews', [
            'applicant_id' => $applicant->id, 'scheduled_at' => now()->addDay()->setTime(10, 0)->toDateTimeString(),
            'mode' => 'online', 'location' => 'https://meet.example.com/abc', 'interviewer_ids' => [$interviewer->id],
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.interviewers.0.id', $interviewer->id)
            ->assertJsonPath('data.applicant.stage', 'interview');

        $this->assertSame('interview', $applicant->fresh()->stage);
        $this->assertSame(1, UserNotification::query()->where('recipient_id', $interviewer->id)->where('type', NotificationType::INTERVIEW_SCHEDULED)->count());
    }

    public function test_only_working_staff_can_be_interviewers(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $notStaff = User::factory()->forTenant($this->tenant)->create();
        $applicant = Applicant::factory()->create();

        $this->postJson('/api/v1/interviews', [
            'applicant_id' => $applicant->id, 'scheduled_at' => now()->addDay()->toDateTimeString(), 'mode' => 'in_person', 'interviewer_ids' => [$notStaff->id],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('interviewer_ids.0');
    }

    public function test_an_interviewer_evaluates_once_and_may_revise_it(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::RECRUITMENT_VIEW]);
        Staff::factory()->create(['user_id' => $admin->id]);
        $interview = Interview::factory()->create();
        $interview->interviewerRows()->create(['user_id' => $admin->id]);

        $this->getJson('/api/v1/interviews?awaiting_my_evaluation=1')->assertOk()->assertJsonCount(1, 'data');

        $this->postJson('/api/v1/interview-evaluations', ['interview_id' => $interview->id, 'scores' => $this->scores(4), 'recommendation' => 'hire'])
            ->assertCreated()
            ->assertJsonPath('data.overall_score', 4);

        $scores = $this->scores(3);
        $scores['communication'] = 5;
        $this->postJson('/api/v1/interview-evaluations', ['interview_id' => $interview->id, 'scores' => $scores, 'recommendation' => 'maybe'])
            ->assertOk()
            ->assertJsonPath('data.overall_score', 3.4)
            ->assertJsonPath('data.recommendation', 'maybe');

        $this->assertSame(1, InterviewEvaluation::query()->count());
        $this->getJson('/api/v1/interviews?awaiting_my_evaluation=1')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/interviews/{$interview->id}")->assertOk()->assertJsonPath('data.average_score', 3.4);
    }

    public function test_someone_not_on_the_interview_cannot_evaluate_it_without_update_rights(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::RECRUITMENT_VIEW]);
        $interview = Interview::factory()->create();

        $this->postJson('/api/v1/interview-evaluations', ['interview_id' => $interview->id, 'scores' => $this->scores(), 'recommendation' => 'hire'])
            ->assertForbidden();
    }

    public function test_every_criterion_needs_a_one_to_five_score(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $interview = Interview::factory()->create();
        $scores = $this->scores();
        $scores['knowledge'] = 7;
        unset($scores['teamwork']);

        $this->postJson('/api/v1/interview-evaluations', ['interview_id' => $interview->id, 'scores' => $scores, 'recommendation' => 'great'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scores.knowledge', 'scores.teamwork', 'recommendation']);
    }

    public function test_a_cancelled_interview_cannot_be_evaluated(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $interview = Interview::factory()->create(['status' => 'cancelled']);

        $this->postJson('/api/v1/interview-evaluations', ['interview_id' => $interview->id, 'scores' => $this->scores(), 'recommendation' => 'hire'])
            ->assertUnprocessable();
    }
}
