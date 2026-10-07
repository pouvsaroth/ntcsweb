<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Models\EvaluationForm;
use App\Models\PerformanceCycle;
use App\Models\Staff;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * HRM > Performance Management's set-up (stage 1) — KPIs, evaluation forms,
 * review cycles, score weights and goals.
 */
class PerformanceSetupTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const ALL = [Permissions::PERFORMANCE_VIEW, Permissions::PERFORMANCE_MANAGE];

    public function test_viewing_needs_view_and_changing_needs_manage(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/v1/kpis')->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::PERFORMANCE_VIEW]);
        foreach (['kpis', 'evaluation-forms', 'performance-cycles', 'performance-goals', 'performance-settings'] as $path) {
            $this->getJson("/api/v1/{$path}")->assertOk();
        }
        $this->postJson('/api/v1/kpis', ['code' => 'PR', 'name' => 'Pass rate'])->assertForbidden();
    }

    public function test_kpis_have_unique_codes_and_an_optional_department_or_position(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);

        $this->postJson('/api/v1/kpis', ['code' => 'PR', 'name' => 'Pass rate', 'unit' => '%', 'target' => 90, 'default_weight' => 50])
            ->assertCreated()
            ->assertJsonPath('data.higher_is_better', true)
            ->assertJsonPath('data.department', null);
        $this->postJson('/api/v1/kpis', ['code' => 'PR', 'name' => 'Again', 'default_weight' => 150, 'department_id' => 99999])
            ->assertUnprocessable()->assertJsonValidationErrors(['code', 'default_weight', 'department_id']);
    }

    public function test_a_form_saves_its_questions_in_order_and_keeps_ids_when_edited(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);

        $form = $this->postJson('/api/v1/evaluation-forms', ['name' => 'Teacher evaluation', 'questions' => [
            ['section' => 'Teaching', 'question' => 'Prepares lessons well', 'type' => 'rating'],
            ['section' => 'Teaching', 'question' => 'What went well?', 'type' => 'text', 'is_required' => false],
        ]])->assertCreated()->assertJsonCount(2, 'data.questions')->json('data');
        $first = $form['questions'][0];

        $edited = $this->putJson("/api/v1/evaluation-forms/{$form['id']}", ['questions' => [
            ['section' => 'Conduct', 'question' => 'Is on time', 'type' => 'rating'],
            ['id' => $first['id'], 'section' => 'Teaching', 'question' => 'Prepares lessons very well', 'type' => 'rating'],
        ]])->assertOk()->json('data.questions');

        $this->assertCount(2, $edited);
        $this->assertSame('Is on time', $edited[0]['question']);
        $this->assertSame($first['id'], $edited[1]['id']);

        $this->postJson('/api/v1/evaluation-forms', ['name' => 'Bad', 'questions' => [['question' => 'X', 'type' => 'stars']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['questions.0.type']);
    }

    public function test_a_form_used_by_a_cycle_cannot_be_deleted(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $used = EvaluationForm::factory()->create();
        PerformanceCycle::factory()->create(['evaluation_form_id' => $used->id]);
        $unused = EvaluationForm::factory()->create();

        $this->deleteJson("/api/v1/evaluation-forms/{$used->id}")->assertUnprocessable();
        $this->deleteJson("/api/v1/evaluation-forms/{$unused->id}")->assertNoContent();
    }

    public function test_cycles_end_after_they_start_and_only_a_draft_can_be_deleted(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);

        $this->postJson('/api/v1/performance-cycles', ['name' => '2026', 'start_date' => '2026-12-31', 'end_date' => '2026-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors(['end_date']);
        $cycle = $this->postJson('/api/v1/performance-cycles', ['name' => '2026 annual', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'])
            ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data');

        $this->putJson("/api/v1/performance-cycles/{$cycle['id']}", ['status' => 'active'])->assertOk();
        $this->deleteJson("/api/v1/performance-cycles/{$cycle['id']}")->assertUnprocessable();
    }

    public function test_score_weights_start_at_40_30_30_and_must_add_up_to_100(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);

        $this->getJson('/api/v1/performance-settings')->assertOk()->assertExactJson(['success' => true, 'data' => ['kpi_weight' => 40, 'goal_weight' => 30, 'manager_weight' => 30]]);
        $this->putJson('/api/v1/performance-settings', ['kpi_weight' => 50, 'goal_weight' => 30, 'manager_weight' => 30])
            ->assertUnprocessable()->assertJsonValidationErrors(['kpi_weight']);
        $this->putJson('/api/v1/performance-settings', ['kpi_weight' => 50, 'goal_weight' => 20, 'manager_weight' => 30])
            ->assertOk()->assertJsonPath('data.kpi_weight', 50);
    }

    public function test_goals_belong_to_a_staff_member_and_completing_one_sets_it_to_100(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $staff = Staff::factory()->create();
        $cycle = PerformanceCycle::factory()->create();

        $goal = $this->postJson('/api/v1/performance-goals', ['staff_id' => $staff->id, 'performance_cycle_id' => $cycle->id, 'title' => 'Raise pass rate to 90%'])
            ->assertCreated()->assertJsonPath('data.status', 'not_started')->assertJsonPath('data.progress', 0)->json('data');

        $this->putJson("/api/v1/performance-goals/{$goal['id']}", ['status' => 'completed'])->assertOk()->assertJsonPath('data.progress', 100);
        $this->putJson("/api/v1/performance-goals/{$goal['id']}", ['progress' => 120])->assertUnprocessable()->assertJsonValidationErrors(['progress']);
        $this->getJson("/api/v1/performance-goals?filter[performance_cycle_id]={$cycle->id}")->assertOk()->assertJsonCount(1, 'data');
    }
}
