<?php

declare(strict_types=1);

namespace Tests\Feature\Hrm;

use App\Models\Permission;
use App\Models\Position;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** HRM > Professional Development: Classroom observation, Coaching & mentoring. */
class ObservationCoachingTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const BASE = '/api/v1/professional-development';

    private Position $teacherPosition;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->actingAsAdminWithPermissions([Permissions::PROFESSIONAL_DEVELOPMENT_VIEW, Permissions::PROFESSIONAL_DEVELOPMENT_MANAGE]);
        $role = Role::factory()->forTenant($this->tenant)->system()->create(['slug' => Role::TEACHER, 'name' => 'Teacher', 'level' => Role::LEVELS[Role::TEACHER]]);
        $this->teacherPosition = Position::factory()->create(['name' => 'Teacher', 'role_id' => $role->id]);
    }

    /** A staff member with a login that holds only the self-service permission. */
    private function person(bool $teacher = true): Staff
    {
        static $n = 0;
        $n++;
        $role = Role::factory()->forTenant($this->tenant)->create(['name' => "Self {$n}", 'slug' => "self-{$n}"]);
        $role->permissions()->attach(Permission::query()->where('slug', Permissions::MY_REQUESTS_VIEW)->pluck('id'));
        $user = User::factory()->forTenant($this->tenant)->create();
        $user->attachRoles($role);

        return Staff::factory()->withUser($user)->create([
            'status' => Staff::STATUS_ACTIVE,
            ...($teacher ? ['position_id' => $this->teacherPosition->id] : []),
        ]);
    }

    private function as(Staff|User $who): void
    {
        $this->actingAsTenantUser($who instanceof Staff ? User::query()->findOrFail($who->user_id) : $who);
    }

    private function form(): int
    {
        return $this->postJson(self::BASE.'/observation-forms', [
            'name' => 'Lesson observation',
            'criteria' => [
                ['section' => 'Planning', 'criterion' => 'Clear lesson objective'],
                ['section' => 'Delivery', 'criterion' => 'Checks for understanding'],
                ['section' => 'Delivery', 'criterion' => 'Manages time well'],
            ],
        ])->assertCreated()->json('data.id');
    }

    private function schedule(Staff $teacher, ?Staff $observer, int $formId): array
    {
        return $this->postJson(self::BASE.'/observations', [
            'staff_id' => $teacher->id,
            'observer_staff_id' => $observer?->id,
            'observation_form_id' => $formId,
            'observed_on' => now()->addDays(2)->toDateString(),
            'start_time' => '08:00',
            'focus' => 'Questioning',
        ])->assertCreated()->json('data');
    }

    public function test_full_observation_flow_observer_records_teacher_acknowledges(): void
    {
        $teacher = $this->person();
        $observer = $this->person(false);
        $formId = $this->form();

        $obs = $this->schedule($teacher, $observer, $formId);
        $this->assertCount(3, $obs['ratings']);
        $this->assertSame('Lesson observation', $obs['form_name']);
        $this->assertSame(1, UserNotification::query()->where('recipient_id', $teacher->user_id)->where('type', NotificationType::OBSERVATION_SCHEDULED)->count());
        $this->assertSame(1, UserNotification::query()->where('recipient_id', $observer->user_id)->where('type', NotificationType::OBSERVATION_SCHEDULED)->count());

        // Editing the form later does not change the observation's criteria.
        $this->putJson(self::BASE."/observation-forms/{$formId}", ['name' => 'v2', 'criteria' => [['criterion' => 'Only one']]])->assertOk();

        // The teacher sees it is scheduled, but no ratings yet, and cannot record.
        $this->as($teacher);
        $mine = $this->getJson(self::BASE.'/observations/'.$obs['id'])->assertOk()->json('data');
        $this->assertNull($mine['ratings']);
        $this->putJson(self::BASE."/observations/{$obs['id']}/record", ['strengths' => 'x'])->assertForbidden();

        // The observer rates and completes it.
        $this->as($observer);
        $ratings = array_map(fn ($r, $i) => ['id' => $r['id'], 'rating' => [4, 5, 3][$i]], $obs['ratings'], array_keys($obs['ratings']));
        $done = $this->putJson(self::BASE."/observations/{$obs['id']}/record", [
            'ratings' => $ratings, 'strengths' => 'Warm rapport', 'improvements' => 'Wait time', 'next_steps' => 'Try think-pair-share', 'complete' => true,
        ])->assertOk()->json('data');
        $this->assertSame('completed', $done['status']);
        $this->assertEquals(4.0, $done['average_rating']);
        $this->assertSame(1, UserNotification::query()->where('recipient_id', $teacher->user_id)->where('type', NotificationType::OBSERVATION_COMPLETED)->count());

        // The teacher reads and acknowledges it.
        $this->as($teacher);
        $seen = $this->getJson(self::BASE.'/observations/'.$obs['id'])->assertOk()->json('data');
        $this->assertSame('Clear lesson objective', $seen['ratings'][0]['criterion']);
        $this->assertTrue($seen['can']['acknowledge']);
        $ack = $this->postJson(self::BASE."/observations/{$obs['id']}/acknowledge", ['comment' => 'Thank you'])->assertOk()->json('data');
        $this->assertNotNull($ack['acknowledged_at']);

        // Once acknowledged it is locked.
        $this->as($observer);
        $this->putJson(self::BASE."/observations/{$obs['id']}/record", ['strengths' => 'changed'])->assertStatus(422);
    }

    public function test_completing_needs_a_rating_and_only_teachers_are_observed(): void
    {
        $teacher = $this->person();
        $other = $this->person(false);
        $formId = $this->form();

        $this->postJson(self::BASE.'/observations', ['staff_id' => $other->id, 'observation_form_id' => $formId, 'observed_on' => '2026-11-02'])
            ->assertUnprocessable()->assertJsonValidationErrors('staff_id');

        $obs = $this->schedule($teacher, null, $formId);
        $this->putJson(self::BASE."/observations/{$obs['id']}/record", ['complete' => true])->assertStatus(422);
        $this->assertSame('scheduled', $this->getJson(self::BASE.'/observations/'.$obs['id'])->json('data.status'));
    }

    public function test_people_only_see_their_own_observations(): void
    {
        $a = $this->person();
        $b = $this->person();
        $formId = $this->form();
        $this->schedule($a, null, $formId);
        $obsB = $this->schedule($b, null, $formId);

        $this->getJson(self::BASE.'/observations')->assertOk()->assertJsonCount(2, 'data');

        $this->as($a);
        $this->getJson(self::BASE.'/observations')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.staff_id', $a->id);
        $this->getJson(self::BASE.'/observations/'.$obsB['id'])->assertNotFound();
        $this->getJson(self::BASE.'/observations/summary')->assertForbidden();
        $this->getJson(self::BASE.'/observation-forms')->assertForbidden();
    }

    public function test_coaching_pairs_people_and_the_coach_logs_sessions(): void
    {
        $coach = $this->person(false);
        $coachee = $this->person();
        $outsider = $this->person();

        $coaching = $this->postJson(self::BASE.'/coachings', [
            'type' => 'mentoring', 'coach_staff_id' => $coach->id, 'staff_id' => $coachee->id,
            'goal' => 'Better questioning', 'start_date' => '2026-11-01',
        ])->assertCreated()->json('data');
        $this->assertSame(1, UserNotification::query()->where('recipient_id', $coach->user_id)->where('type', NotificationType::COACHING_ASSIGNED)->count());
        $this->assertSame(1, UserNotification::query()->where('recipient_id', $coachee->user_id)->where('type', NotificationType::COACHING_ASSIGNED)->count());

        // The coach adds a session and closes it; cannot change the goal.
        $this->as($coach);
        $this->postJson(self::BASE."/coachings/{$coaching['id']}/sessions", ['session_date' => '2026-11-03', 'topic' => 'Wait time', 'duration_minutes' => 30, 'action_items' => 'Count to five'])
            ->assertCreated()->assertJsonCount(1, 'data.sessions');
        $this->putJson(self::BASE."/coachings/{$coaching['id']}", ['goal' => 'Something else', 'status' => 'completed', 'outcome' => 'Much improved'])->assertOk()
            ->assertJsonPath('data.goal', 'Better questioning')
            ->assertJsonPath('data.status', 'completed');

        // The coachee reads it but cannot add sessions.
        $this->as($coachee);
        $this->getJson(self::BASE."/coachings/{$coaching['id']}")->assertOk()->assertJsonPath('data.sessions.0.topic', 'Wait time');
        $this->postJson(self::BASE."/coachings/{$coaching['id']}/sessions", ['session_date' => '2026-11-04', 'topic' => 'x'])->assertForbidden();
        $this->getJson(self::BASE.'/coachings')->assertOk()->assertJsonCount(1, 'data');

        // Someone else sees nothing.
        $this->as($outsider);
        $this->getJson(self::BASE.'/coachings')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::BASE."/coachings/{$coaching['id']}")->assertNotFound();
    }
}
