<?php

declare(strict_types=1);

namespace Tests\Feature\Hrm;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** HRM > Professional Development: Development plan, Training reports. */
class DevelopmentPlanReportTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const BASE = '/api/v1/professional-development';

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->actingAsAdminWithPermissions([Permissions::PROFESSIONAL_DEVELOPMENT_VIEW, Permissions::PROFESSIONAL_DEVELOPMENT_MANAGE]);
    }

    /** A staff member with a login that holds only the self-service permission. */
    private function person(): Staff
    {
        static $n = 0;
        $n++;
        $role = Role::factory()->forTenant($this->tenant)->create(['name' => "Self {$n}", 'slug' => "self-{$n}"]);
        $role->permissions()->attach(Permission::query()->where('slug', Permissions::MY_REQUESTS_VIEW)->pluck('id'));
        $user = User::factory()->forTenant($this->tenant)->create();
        $user->attachRoles($role);

        return Staff::factory()->withUser($user)->create(['status' => Staff::STATUS_ACTIVE]);
    }

    private function as(Staff|User $who): void
    {
        $this->actingAsTenantUser($who instanceof Staff ? User::query()->findOrFail($who->user_id) : $who);
    }

    public function test_draft_plan_is_hidden_until_shared_then_staff_and_reviewer_follow_it(): void
    {
        $staff = $this->person();
        $reviewer = $this->person();
        $outsider = $this->person();

        $plan = $this->postJson(self::BASE.'/development-plans', [
            'staff_id' => $staff->id,
            'reviewer_staff_id' => $reviewer->id,
            'title' => 'Plan 2026–2027',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'goals' => [
                ['title' => 'Use group work weekly', 'area' => 'Pedagogy', 'target_date' => now()->addMonths(3)->toDateString()],
                ['title' => 'Finish ICT course', 'area' => 'ICT'],
            ],
        ])->assertCreated()->json('data');
        $this->assertSame('draft', $plan['status']);
        $this->assertCount(2, $plan['goals']);
        $this->assertSame(0, UserNotification::query()->where('type', NotificationType::DEVELOPMENT_PLAN_SHARED)->count());

        // A draft is only for managers.
        $this->as($staff);
        $this->getJson(self::BASE.'/development-plans')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::BASE."/development-plans/{$plan['id']}")->assertNotFound();

        // Sharing it tells the staff member and the reviewer.
        $this->as($this->manager);
        $goals = $plan['goals'];
        $this->putJson(self::BASE."/development-plans/{$plan['id']}", [
            'status' => 'active',
            'goals' => [
                ['id' => $goals[1]['id'], 'title' => 'Finish ICT course (online)'],
                ['id' => $goals[0]['id'], 'title' => $goals[0]['title'], 'area' => 'Pedagogy'],
                ['title' => 'Observe a colleague'],
            ],
        ])->assertOk()->assertJsonPath('data.status', 'active')->assertJsonCount(3, 'data.goals')
            ->assertJsonPath('data.goals.0.id', $goals[1]['id']);
        foreach ([$staff, $reviewer] as $who) {
            $this->assertSame(1, UserNotification::query()->where('recipient_id', $who->user_id)->where('type', NotificationType::DEVELOPMENT_PLAN_SHARED)->count());
        }

        // The staff member updates progress; done means 100.
        $this->as($staff);
        $this->getJson(self::BASE.'/development-plans?mine=own')->assertOk()->assertJsonCount(1, 'data');
        $this->putJson(self::BASE."/development-plan-goals/{$goals[0]['id']}/progress", ['status' => 'in_progress', 'progress' => 40, 'staff_note' => 'Tried twice'])
            ->assertOk();
        $detail = $this->putJson(self::BASE."/development-plan-goals/{$goals[1]['id']}/progress", ['status' => 'done', 'progress' => 10])
            ->assertOk()->json('data');
        $this->assertSame(100, $detail['goals'][0]['progress']);
        $this->assertSame(47, $detail['progress']); // (100 + 40 + 0) / 3
        $this->assertSame(1, $detail['goals_done']);
        // …but cannot edit the plan itself.
        $this->putJson(self::BASE."/development-plans/{$plan['id']}", ['title' => 'Mine now'])->assertForbidden();

        // An outsider sees nothing.
        $this->as($outsider);
        $this->getJson(self::BASE."/development-plans/{$plan['id']}")->assertNotFound();
        $this->putJson(self::BASE."/development-plan-goals/{$goals[0]['id']}/progress", ['status' => 'done'])->assertForbidden();

        // The reviewer writes the review and closes it; then progress is locked.
        $this->as($reviewer);
        $this->getJson(self::BASE.'/development-plans?mine=reviewing')->assertOk()->assertJsonCount(1, 'data');
        $this->putJson(self::BASE."/development-plans/{$plan['id']}", ['status' => 'completed', 'review_note' => 'Good year', 'title' => 'ignored'])
            ->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.title', 'Plan 2026–2027');
        $this->as($staff);
        $this->putJson(self::BASE."/development-plan-goals/{$goals[0]['id']}/progress", ['status' => 'done'])->assertForbidden();
    }

    public function test_goals_can_link_a_training_and_a_coaching(): void
    {
        $staff = $this->person();
        $coach = $this->person();
        $training = $this->postJson(self::BASE.'/trainings', ['title' => 'ICT basics', 'start_date' => now()->toDateString(), 'end_date' => now()->toDateString()])
            ->assertCreated()->json('data.id');
        $coaching = $this->postJson(self::BASE.'/coachings', ['coach_staff_id' => $coach->id, 'staff_id' => $staff->id, 'goal' => 'Questioning', 'start_date' => now()->toDateString()])
            ->assertCreated()->json('data.id');

        $options = $this->getJson(self::BASE."/development-plans/options?staff_id={$staff->id}")->assertOk()->json('data');
        $this->assertSame([$training], array_column($options['trainings'], 'id'));
        $this->assertSame([$coaching], array_column($options['coachings'], 'id'));

        $plan = $this->postJson(self::BASE.'/development-plans', [
            'staff_id' => $staff->id,
            'title' => 'Plan',
            'start_date' => now()->toDateString(),
            'status' => 'active',
            'goals' => [['title' => 'ICT', 'training_id' => $training, 'coaching_id' => $coaching]],
        ])->assertCreated()->json('data');
        $this->assertSame('ICT basics', $plan['goals'][0]['training']['title']);
        $this->assertSame('Questioning', $plan['goals'][0]['coaching']['goal']);
    }

    public function test_training_report_counts_attended_hours_and_lists_staff_without_training(): void
    {
        $a = $this->person();
        $b = $this->person();
        $idle = $this->person();
        $year = now()->year;

        $done = $this->postJson(self::BASE.'/trainings', [
            'title' => 'Workshop', 'category' => 'Pedagogy', 'status' => 'completed', 'hours' => 6, 'cost' => 100,
            'start_date' => "{$year}-03-10", 'end_date' => "{$year}-03-10", 'participant_ids' => [$a->id, $b->id],
        ])->assertCreated()->json('data');
        // b was absent.
        $bRow = collect($done['participants'])->firstWhere('staff_id', $b->id);
        $aRow = collect($done['participants'])->firstWhere('staff_id', $a->id);
        $this->putJson(self::BASE."/trainings/{$done['id']}/attendance", ['rows' => [
            ['id' => $aRow['id'], 'attendance' => 'attended', 'result' => 'passed'],
            ['id' => $bRow['id'], 'attendance' => 'absent'],
        ]])->assertOk();
        // A planned one adds to the count but not to hours; a cancelled one is left out.
        $this->postJson(self::BASE.'/trainings', ['title' => 'Later', 'category' => 'ICT', 'hours' => 3, 'start_date' => "{$year}-06-01", 'end_date' => "{$year}-06-01", 'participant_ids' => [$a->id]])->assertCreated();
        $cancelled = $this->postJson(self::BASE.'/trainings', ['title' => 'Off', 'start_date' => "{$year}-04-01", 'end_date' => "{$year}-04-01"])->assertCreated()->json('data.id');
        $this->putJson(self::BASE."/trainings/{$cancelled}", ['status' => 'cancelled'])->assertOk();

        $r = $this->getJson(self::BASE."/reports?from={$year}-01-01&to={$year}-12-31")->assertOk()->json('data');
        $this->assertSame(2, $r['totals']['trainings']);
        $this->assertSame(1, $r['totals']['completed']);
        $this->assertSame(1, $r['totals']['cancelled']);
        $this->assertSame(50, $r['totals']['attendance_rate']);
        $this->assertEquals(6, $r['totals']['hours']);
        $this->assertEquals(100, $r['totals']['cost']);
        $this->assertSame(1, $r['totals']['staff_trained']);
        $this->assertCount(12, $r['by_month']);
        $this->assertEquals(6, collect($r['by_month'])->firstWhere('month', "{$year}-03")['hours']);
        $this->assertSame(['Pedagogy', 'ICT'], array_column($r['by_category'], 'category'));

        $rows = collect($r['by_staff'])->keyBy('staff_id');
        $this->assertSame(1, $rows[$a->id]['trainings']);
        $this->assertEquals(6, $rows[$a->id]['hours']);
        $this->assertSame(0, $rows[$b->id]['trainings']);
        $this->assertTrue($rows->has($idle->id));
        $this->assertSame($a->id, $r['by_staff'][0]['staff_id']);

        $csv = $this->get(self::BASE."/reports?from={$year}-01-01&to={$year}-12-31&format=csv")->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('Content-Type'));
        $this->assertStringContainsString($a->employee_code ?? $a->first_name, $csv->getContent());

        // Self-service staff cannot open reports.
        $this->as($a);
        $this->getJson(self::BASE.'/reports')->assertForbidden();
    }
}
