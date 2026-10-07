<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Models\Department;
use App\Models\EvaluationForm;
use App\Models\Kpi;
use App\Models\PerformanceCycle;
use App\Models\PerformanceGoal;
use App\Models\PerformanceReview;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * HRM > Performance Management's reviews (stage 2) — launching a cycle,
 * the self and manager assessments, and the score. One review throughout:
 * a teacher who reports to a manager, two KPIs (weights 60 / 40), one goal
 * and a form with two 1–5 questions and one optional written one.
 */
class PerformanceReviewTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const ALL = [Permissions::PERFORMANCE_VIEW, Permissions::PERFORMANCE_MANAGE];

    private User $hr;

    private User $managerUser;

    private User $staffUser;

    private Staff $manager;

    private Staff $staff;

    private PerformanceCycle $cycle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hr = $this->actingAsAdminWithPermissions(self::ALL);
        $this->managerUser = User::factory()->forTenant($this->tenant)->create();
        $this->manager = Staff::factory()->withUser($this->managerUser)->create();
        $this->staffUser = User::factory()->forTenant($this->tenant)->create();
        $this->staff = Staff::factory()->withUser($this->staffUser)->create(['reports_to_staff_id' => $this->manager->id]);

        Kpi::factory()->create(['code' => 'A', 'name' => 'Pass rate', 'default_weight' => 60]);
        Kpi::factory()->create(['code' => 'C', 'name' => 'Attendance', 'default_weight' => 40]);
        // Another department's KPI isn't theirs.
        Kpi::factory()->create(['code' => 'B', 'name' => 'Sales', 'department_id' => Department::factory()->create()->id]);

        $form = EvaluationForm::factory()->create();
        $form->questions()->create(['section' => 'Teaching', 'question' => 'Prepares lessons', 'type' => 'rating', 'sort_order' => 0]);
        $form->questions()->create(['section' => 'Teaching', 'question' => 'Manages the class', 'type' => 'rating', 'sort_order' => 1]);
        $form->questions()->create(['section' => 'Overall', 'question' => 'Anything else?', 'type' => 'text', 'is_required' => false, 'sort_order' => 2]);

        $this->cycle = PerformanceCycle::factory()->create(['evaluation_form_id' => $form->id]);
        PerformanceGoal::factory()->create(['staff_id' => $this->staff->id, 'performance_cycle_id' => $this->cycle->id]);
    }

    private function launch(): array
    {
        $this->actingAsTenantUser($this->hr);
        $this->postJson("/api/v1/performance-cycles/{$this->cycle->id}/launch", ['staff_ids' => [$this->staff->id]])->assertCreated()->assertJsonPath('data.launched', 1);

        return $this->getJson('/api/v1/performance-reviews/'.PerformanceReview::query()->where('staff_id', $this->staff->id)->value('id'))->assertOk()->json('data');
    }

    /** @param  array<string, mixed>  $review */
    private function rate(array $review, string $side, array $kpiRatings, int $goalRating, array $answerRatings): array
    {
        $field = "{$side}_rating";

        return [
            'kpis' => collect($review['kpis'])->values()->map(fn ($k, $i) => ['id' => $k['id'], $field => $kpiRatings[$i]])->all(),
            'goals' => collect($review['goals'])->map(fn ($g) => ['id' => $g['id'], $field => $goalRating])->all(),
            'answers' => collect($review['answers'])->where('type', 'rating')->values()->map(fn ($a, $i) => ['id' => $a['id'], $field => $answerRatings[$i]])->all(),
        ];
    }

    public function test_launching_copies_their_kpis_and_the_form_and_tells_them(): void
    {
        $review = $this->launch();

        $this->assertSame($this->manager->id, $review['reviewer']['id']);
        $this->assertSame('self_assessment', $review['status']);
        $this->assertSame(['Pass rate', 'Attendance'], collect($review['kpis'])->pluck('name')->all());
        $this->assertCount(3, $review['answers']);
        $this->assertCount(1, $review['goals']);
        $this->assertSame('active', $this->cycle->fresh()->status);
        $this->assertSame(1, UserNotification::where('recipient_id', $this->staffUser->id)->where('type', NotificationType::PERFORMANCE_SELF_ASSESSMENT_OPEN)->count());

        // Launching again skips who's already in.
        $this->postJson("/api/v1/performance-cycles/{$this->cycle->id}/launch", ['staff_ids' => [$this->staff->id]])->assertCreated()->assertJsonPath('data.launched', 0);
        $this->getJson("/api/v1/performance-cycles/{$this->cycle->id}/candidates")->assertOk()->assertJsonMissing(['id' => $this->staff->id]);
    }

    public function test_a_review_goes_self_then_manager_then_completed_with_a_weighted_score(): void
    {
        $review = $this->launch();

        // The staff member: nothing rated yet → can't send.
        $this->actingAsTenantUser($this->staffUser);
        $this->getJson('/api/v1/my-performance-reviews')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/my-performance-reviews/{$review['id']}/submit")->assertUnprocessable();
        $this->putJson("/api/v1/my-performance-reviews/{$review['id']}", [...$this->rate($review, 'self', [4, 4], 3, [4, 4]), 'self_comment' => 'Good year'])->assertOk();
        $this->postJson("/api/v1/my-performance-reviews/{$review['id']}/submit")->assertOk()->assertJsonPath('data.status', 'manager_assessment');
        $this->assertSame(1, UserNotification::where('recipient_id', $this->managerUser->id)->where('type', NotificationType::PERFORMANCE_MANAGER_ASSESSMENT_DUE)->count());
        // Sent — can't be changed any more.
        $this->putJson("/api/v1/my-performance-reviews/{$review['id']}", ['self_comment' => 'Changed'])->assertUnprocessable();

        // The manager sees the self ratings, rates, and completes it.
        $this->actingAsTenantUser($this->managerUser);
        $team = $this->getJson("/api/v1/team-performance-reviews/{$review['id']}")->assertOk()->json('data');
        $this->assertSame(4, $team['kpis'][0]['self_rating']);
        $this->assertSame('Good year', $team['self_comment']);
        $this->putJson("/api/v1/team-performance-reviews/{$review['id']}", [...$this->rate($review, 'manager', [5, 3], 4, [4, 5]), 'manager_comment' => 'Well done'])->assertOk();

        // Before it's completed, the staff member can't see the manager's ratings.
        $this->actingAsTenantUser($this->staffUser);
        $mine = $this->getJson("/api/v1/my-performance-reviews/{$review['id']}")->assertOk()->json('data');
        $this->assertNull($mine['kpis'][0]['manager_rating']);
        $this->assertNull($mine['manager_comment']);

        $this->actingAsTenantUser($this->managerUser);
        $done = $this->postJson("/api/v1/team-performance-reviews/{$review['id']}/submit")->assertOk()->json('data');

        // KPIs 5×60 + 3×40 → 4.2; goal 4; form (4 + 5) / 2 → 4.5; final 4.2×40% + 4×30% + 4.5×30% = 4.23.
        $this->assertSame('completed', $done['status']);
        $this->assertEquals(4.2, $done['kpi_score']);
        $this->assertEquals(4.0, $done['goal_score']);
        $this->assertEquals(4.5, $done['manager_score']);
        $this->assertEquals(4.23, $done['final_score']);
        $this->assertEquals(3.8, $done['self_score']); // (4 + 4 + 3 + 4 + 4) / 5

        $this->actingAsTenantUser($this->staffUser);
        $this->getJson("/api/v1/my-performance-reviews/{$review['id']}")->assertOk()->assertJsonPath('data.manager_comment', 'Well done');
        $this->assertSame(1, UserNotification::where('recipient_id', $this->staffUser->id)->where('type', NotificationType::PERFORMANCE_REVIEW_COMPLETED)->count());

        $this->actingAsTenantUser($this->hr);
        $scores = $this->getJson("/api/v1/performance-scores?performance_cycle_id={$this->cycle->id}")->assertOk()->json('data');
        $this->assertSame(1, $scores['counts']['completed']);
        $this->assertEquals(4.23, $scores['averages']['final_score']);
        $this->assertSame(1, $scores['bands'][4]);
    }

    public function test_a_part_with_nothing_rated_shares_its_weight_with_the_others(): void
    {
        $review = $this->launch();
        PerformanceGoal::query()->delete();

        $this->actingAsTenantUser($this->hr);
        $this->postJson("/api/v1/performance-reviews/{$review['id']}/send-to-manager")->assertOk();

        $this->actingAsTenantUser($this->managerUser);
        $ratings = $this->rate($review, 'manager', [4, 4], 1, [2, 2]);
        unset($ratings['goals']);
        $this->putJson("/api/v1/team-performance-reviews/{$review['id']}", $ratings)->assertOk();
        $done = $this->postJson("/api/v1/team-performance-reviews/{$review['id']}/submit")->assertOk()->json('data');

        // No goals: KPIs 4 and form 2 weigh 40 : 30 → (4×40 + 2×30) / 70 = 3.14.
        $this->assertNull($done['goal_score']);
        $this->assertEquals(3.14, $done['final_score']);
    }

    public function test_only_the_staff_member_and_their_manager_can_open_a_review(): void
    {
        $review = $this->launch();
        $stranger = User::factory()->forTenant($this->tenant)->create();
        Staff::factory()->withUser($stranger)->create();

        $this->actingAsTenantUser($stranger);
        $this->getJson("/api/v1/my-performance-reviews/{$review['id']}")->assertNotFound();
        $this->getJson("/api/v1/team-performance-reviews/{$review['id']}")->assertNotFound();
        $this->getJson('/api/v1/team-performance-reviews')->assertOk()->assertJsonCount(0, 'data');

        // And the manager can't complete it before the staff member sends theirs.
        $this->actingAsTenantUser($this->managerUser);
        $this->postJson("/api/v1/team-performance-reviews/{$review['id']}/submit")->assertUnprocessable();
    }

    public function test_an_account_without_a_staff_record_just_has_no_reviews(): void
    {
        $this->getJson('/api/v1/my-performance-reviews')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/team-performance-reviews')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/my-performance-reviews/1')->assertUnprocessable();
    }

    public function test_hr_changes_the_reviewer_and_kpis_and_reopens_a_completed_review(): void
    {
        $review = $this->launch();
        $other = Staff::factory()->create();

        $this->putJson("/api/v1/performance-reviews/{$review['id']}", ['reviewer_staff_id' => $this->staff->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['reviewer_staff_id']);
        $this->putJson("/api/v1/performance-reviews/{$review['id']}", ['reviewer_staff_id' => $other->id])->assertOk()->assertJsonPath('data.reviewer.id', $other->id);

        $kept = $review['kpis'][0];
        $this->putJson("/api/v1/performance-reviews/{$review['id']}/kpis", ['kpis' => [
            ['name' => 'Parent feedback', 'weight' => 50],
            ['id' => $kept['id'], 'name' => $kept['name'], 'target' => 95, 'weight' => 50],
        ]])->assertOk()->assertJsonCount(2, 'data.kpis')->assertJsonPath('data.kpis.0.name', 'Parent feedback')->assertJsonPath('data.kpis.1.id', $kept['id']);

        $model = PerformanceReview::query()->findOrFail($review['id']);
        $model->update(['status' => PerformanceReview::STATUS_COMPLETED, 'final_score' => 4]);
        $this->postJson("/api/v1/performance-reviews/{$review['id']}/reopen")->assertOk()->assertJsonPath('data.status', 'manager_assessment')->assertJsonPath('data.final_score', null);
    }
}
