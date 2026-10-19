<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Models\School;
use App\Platform\Models\SupportSession;
use App\Platform\Support\SupportViews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

/**
 * Stage 6b-1 (docs/backoffice/stage-6-support.md): opening, listing and
 * ending read-only support views from the back office.
 */
class SupportViewsTest extends TestCase
{
    use HasPlatformStaff, RefreshDatabase;

    private function schoolWithUser(array $tenant = []): array
    {
        $school = Tenant::factory()->create($tenant);
        $user = User::factory()->create(['tenant_id' => $school->id, 'name' => 'Dara Owner', 'status' => User::STATUS_ACTIVE]);

        return [$school, $user];
    }

    private function open(Tenant $school, User $user, string $reason = 'Ticket 214: invoice list empty')
    {
        return $this->postJson("/api/v1/platform/schools/{$school->id}/support/sessions", ['user_id' => $user->id, 'reason' => $reason]);
    }

    public function test_opening_a_view_records_it_and_returns_a_one_time_console_ticket(): void
    {
        [$school, $user] = $this->schoolWithUser();
        $staff = $this->platformStaff(['platform.support.view'], ['name' => 'Sokha Support']);
        $this->actingAsStaff($staff);
        $lastLogin = $user->last_login_at;

        $data = $this->open($school, $user)->assertCreated()->json('data');

        $this->assertSame('open', $data['state']);
        $this->assertSame('Sokha Support', $data['staff']['name']);
        $this->assertSame($user->id, $data['user']['id']);
        $this->assertStringContainsString('/support-view?ticket=', $data['url']);
        $this->assertStringContainsString('tenant='.$school->slug, $data['url']);

        parse_str((string) parse_url($data['url'], PHP_URL_QUERY), $query);
        $session = SupportSession::query()->findOrFail($data['id']);
        // Stored hashed only; good for 60 seconds; the view for 30 minutes by default.
        $this->assertSame(SupportViews::hash($query['ticket']), $session->ticket_hash);
        $this->assertSame(60, (int) $session->started_at->diffInSeconds($session->ticket_expires_at));
        $this->assertSame(30, (int) $session->started_at->diffInMinutes($session->expires_at));

        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'support.view_opened', 'tenant_id' => $school->id, 'staff_id' => $staff->id]);
        // The school sees it; it is not their sign-in.
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $school->id, 'action' => 'SUPPORT_VIEW',
            'description' => 'Support (NewTech staff) viewed as Dara Owner: Ticket 214: invoice list empty',
        ]);
        $this->assertDatabaseMissing('audit_logs', ['tenant_id' => $school->id, 'action' => 'LOGIN']);
        $this->assertEquals($lastLogin, $user->fresh()->last_login_at);
    }

    public function test_a_reason_is_required(): void
    {
        [$school, $user] = $this->schoolWithUser();
        $this->actingAsStaff($this->platformStaff(['platform.support.view']));

        $this->open($school, $user, '')->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame(0, SupportSession::query()->count());
    }

    public function test_only_an_active_school_can_be_viewed(): void
    {
        $this->actingAsStaff($this->platformStaff(['platform.support.view']));

        foreach ([Tenant::STATUS_SUSPENDED, Tenant::STATUS_ARCHIVED, Tenant::STATUS_PROVISIONING, Tenant::STATUS_FAILED] as $status) {
            [$school, $user] = $this->schoolWithUser(['status' => $status]);
            $this->open($school, $user)->assertUnprocessable()->assertJsonValidationErrors('school');
        }
        $this->assertSame(0, SupportSession::query()->count());
    }

    public function test_only_an_active_user_of_that_school(): void
    {
        [$school] = $this->schoolWithUser();
        [, $other] = $this->schoolWithUser();
        $invited = User::factory()->create(['tenant_id' => $school->id, 'status' => User::STATUS_INVITED]);
        $this->actingAsStaff($this->platformStaff(['platform.support.view']));

        $this->open($school, $other)->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->open($school, $invited)->assertUnprocessable()->assertJsonValidationErrors('user_id');
    }

    public function test_support_read_alone_cannot_open_or_end_a_view(): void
    {
        [$school, $user] = $this->schoolWithUser();
        $this->actingAsStaff($this->platformStaff(['platform.support.view']));
        $id = $this->open($school, $user)->json('data.id');

        $this->actingAsStaff($this->platformStaff(['platform.support.read']));
        $this->open($school, $user)->assertNotFound(); // refusals are 404 here
        $this->postJson("/api/v1/platform/support/sessions/{$id}/end")->assertNotFound();
        $this->getJson("/api/v1/platform/schools/{$school->id}/support/users")->assertNotFound();
        // …but it can see them.
        $this->getJson('/api/v1/platform/support/sessions')->assertOk()->assertJsonPath('data.0.id', $id);
    }

    public function test_the_user_list_puts_the_owner_first_and_leaves_out_inactive_users(): void
    {
        [$school, $user] = $this->schoolWithUser();
        $owner = User::factory()->create(['tenant_id' => $school->id, 'name' => 'Zed Owner', 'status' => User::STATUS_ACTIVE]);
        User::factory()->create(['tenant_id' => $school->id, 'status' => User::STATUS_SUSPENDED]);
        School::for($school)->forceFill(['owner_user_id' => $owner->id])->save();
        $this->actingAsStaff($this->platformStaff(['platform.support.view']));

        $users = $this->getJson("/api/v1/platform/schools/{$school->id}/support/users")->assertOk()->json('data');

        $this->assertSame([$owner->id, $user->id], array_column($users, 'id'));
        $this->assertTrue($users[0]['is_owner']);
    }

    public function test_ending_a_view_and_a_timed_out_view(): void
    {
        [$school, $user] = $this->schoolWithUser();
        $staff = $this->platformStaff(['platform.support.read', 'platform.support.view']);
        $this->actingAsStaff($staff);
        $id = $this->open($school, $user)->json('data.id');

        $ended = $this->postJson("/api/v1/platform/support/sessions/{$id}/end")->assertOk()->json('data');
        $this->assertSame('ended', $ended['state']);
        $this->assertSame('staff', $ended['ended_by']);
        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'support.view_ended', 'tenant_id' => $school->id]);
        $this->postJson("/api/v1/platform/support/sessions/{$id}/end")->assertUnprocessable();

        $late = $this->open($school, $user)->json('data.id');
        SupportSession::query()->whereKey($late)->update(['expires_at' => now()->subMinute()]);
        $shown = $this->getJson("/api/v1/platform/support/sessions/{$late}")->assertOk()->json('data');
        $this->assertSame('timed_out', $shown['state']);
        $this->assertSame('timeout', $shown['ended_by']);
        $this->postJson("/api/v1/platform/support/sessions/{$late}/end")->assertUnprocessable();
    }

    public function test_the_list_filters_by_school_and_open_and_shows_the_pages_viewed(): void
    {
        [$a, $userA] = $this->schoolWithUser();
        [$b, $userB] = $this->schoolWithUser();
        $this->actingAsStaff($this->platformStaff(['platform.support.read', 'platform.support.view']));
        $idA = $this->open($a, $userA)->json('data.id');
        $idB = $this->open($b, $userB)->json('data.id');
        $this->postJson("/api/v1/platform/support/sessions/{$idB}/end")->assertOk();
        DB::connection(config('tenancy.database.central_connection'))->table('bo_support_session_events')->insert([
            ['support_session_id' => $idA, 'method' => 'GET', 'path' => '/api/v1/invoices', 'status' => 200, 'at' => now()],
            ['support_session_id' => $idA, 'method' => 'POST', 'path' => '/api/v1/invoices', 'status' => 403, 'at' => now()],
        ]);

        $this->getJson("/api/v1/platform/support/sessions?filter[tenant_id]={$a->id}")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.events_count', 2);
        $this->getJson('/api/v1/platform/support/sessions?filter[state]=open')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $idA);

        $events = $this->getJson("/api/v1/platform/support/sessions/{$idA}")->assertOk()->json('data.events');
        $this->assertSame(['GET', 'POST'], array_column($events, 'method'));
        $this->assertSame(403, $events[1]['status']);
    }

    public function test_the_view_length_is_a_setting(): void
    {
        [$school, $user] = $this->schoolWithUser();
        $this->actingAsStaff($this->platformStaff(['platform.support.read', 'platform.support.view']));

        $this->patchJson('/api/v1/platform/support/settings', ['duration_minutes' => 2])->assertUnprocessable();
        $this->patchJson('/api/v1/platform/support/settings', ['duration_minutes' => 45])->assertOk()->assertJsonPath('data.duration_minutes', 45);
        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'support.settings_updated']);

        $session = SupportSession::query()->findOrFail($this->open($school, $user)->json('data.id'));
        $this->assertSame(45, (int) $session->started_at->diffInMinutes($session->expires_at));
    }
}
