<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Models\PlatformStaff;
use App\Platform\Models\SupportSession;
use App\Platform\Support\SupportViewSession;
use App\Platform\Support\SupportViews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

/**
 * Stage 6b-2 (docs/backoffice/stage-6-support.md): the console side of a
 * support view — the ticket, read-only, the page log, time-out, End and
 * sign-out, and nothing of it counting as the user's own sign-in.
 */
class SupportViewConsoleTest extends TestCase
{
    use HasPlatformStaff, RefreshDatabase;

    private Tenant $school;

    private User $user;

    private PlatformStaff $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = Tenant::factory()->create(['slug' => 'newtech', 'timezone' => 'Asia/Phnom_Penh']);
        $this->user = User::factory()->create(['tenant_id' => $this->school->id, 'name' => 'Dara Owner', 'status' => User::STATUS_ACTIVE]);
        $this->user->forceFill(['last_login_at' => '2026-10-01 08:00:00', 'last_login_ip' => '10.0.0.9'])->save();
        $this->staff = $this->platformStaff(['platform.support.view'], ['name' => 'Sokha Support']);

        // A first-party SPA request (session cookie), on the shared console domain.
        $this->withHeader('Origin', 'http://localhost');
    }

    /** @return array{0: SupportSession, 1: string} the view and its ticket */
    private function openView(?Tenant $school = null, ?User $user = null): array
    {
        // Back office requests run in UTC; a console request earlier in this
        // test process left the school's timezone behind.
        $opened = SupportViewSession::inUtc(fn () => app(SupportViews::class)->open($school ?? $this->school, $user ?? $this->user, 'Ticket 214: invoice list empty', $this->staff));
        parse_str((string) parse_url($opened['url'], PHP_URL_QUERY), $query);

        return [$opened['session'], $query['ticket']];
    }

    private function enter(string $ticket, string $tenant = 'newtech'): TestResponse
    {
        return $this->withHeader('X-Tenant', $tenant)->postJson('/api/v1/auth/support-view', ['ticket' => $ticket]);
    }

    /** @return list<array{method: string, path: string, status: int|null}> */
    private function events(SupportSession $view): array
    {
        return DB::connection(config('tenancy.database.central_connection'))->table('bo_support_session_events')
            ->where('support_session_id', $view->id)->orderBy('id')
            ->get(['method', 'path', 'status'])->map(fn ($e) => (array) $e)->all();
    }

    public function test_the_ticket_signs_in_as_the_user_without_it_being_their_sign_in(): void
    {
        [$view, $ticket] = $this->openView();

        $this->enter($ticket)->assertOk()->assertJsonPath('data.support_view.staff', 'Sokha Support');
        $this->assertAuthenticatedAs($this->user, 'web');

        $me = $this->getJson('/api/v1/auth/me')->assertOk();
        $me->assertJsonPath('data.id', $this->user->id);
        $me->assertJsonPath('meta.support_view.id', $view->id);
        // Still open right after entering, though this request runs in the school's own time.
        $this->assertSame(SupportViewSession::inUtc(fn () => $view->expires_at->toIso8601String()), $me->json('meta.support_view.expires_at'));

        $fresh = $this->user->fresh();
        $this->assertSame('2026-10-01 08:00:00', $fresh->last_login_at?->format('Y-m-d H:i:s'));
        $this->assertSame('10.0.0.9', $fresh->last_login_ip);
        $this->assertSame(0, $this->user->loginSessions()->count());
        $this->assertDatabaseMissing('audit_logs', ['tenant_id' => $this->school->id, 'action' => 'LOGIN']);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $this->school->id, 'action' => 'SUPPORT_VIEW']);
        $this->assertNotNull($view->fresh()->ticket_used_at);
    }

    public function test_a_ticket_works_once_for_60_seconds_on_its_own_school_only(): void
    {
        Tenant::factory()->create(['slug' => 'other']);

        [, $ticket] = $this->openView();
        $this->enter($ticket, 'other')->assertUnprocessable()->assertJsonValidationErrors('ticket');
        $this->assertGuest('web');

        $this->enter($ticket)->assertOk();
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->enter($ticket)->assertUnprocessable(); // used

        [, $late] = $this->openView();
        $this->travel(61)->seconds();
        $this->enter($late)->assertUnprocessable(); // expired
        $this->assertGuest('web');

        $this->enter('not-a-ticket')->assertUnprocessable();
    }

    public function test_reads_work_and_every_change_is_refused_and_everything_is_logged(): void
    {
        [$view, $ticket] = $this->openView();
        $this->enter($ticket)->assertOk();

        $this->getJson('/api/v1/auth/me')->assertOk();
        foreach (['postJson' => '/api/v1/students', 'putJson' => '/api/v1/students/1', 'patchJson' => '/api/v1/students/1', 'deleteJson' => '/api/v1/students/1'] as $call => $path) {
            $this->{$call}($path, [])->assertForbidden()->assertJsonPath('error.code', 'SUPPORT_VIEW_READ_ONLY');
        }
        $this->postJson('/api/v1/auth/change-password', ['current_password' => 'x', 'password' => 'Newpass-2026', 'password_confirmation' => 'Newpass-2026'])
            ->assertForbidden();

        $events = $this->events($view);
        $this->assertSame(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'POST'], array_column($events, 'method'));
        $this->assertSame('/api/v1/auth/me', $events[0]['path']);
        $this->assertSame(200, $events[0]['status']);
        $this->assertSame([403, 403, 403, 403, 403], array_column(array_slice($events, 1), 'status'));
    }

    public function test_end_from_the_back_office_stops_the_next_request(): void
    {
        [$view, $ticket] = $this->openView();
        $this->enter($ticket)->assertOk();
        $this->getJson('/api/v1/auth/me')->assertOk();

        SupportViewSession::inUtc(fn () => app(SupportViews::class)->end($view->fresh(), $this->staff));

        $this->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('error.code', 'SUPPORT_VIEW_ENDED');
        $this->assertGuest('web');
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_the_view_times_out(): void
    {
        [$view, $ticket] = $this->openView();
        $this->enter($ticket)->assertOk();

        $this->travel(29)->minutes();
        $this->getJson('/api/v1/auth/me')->assertOk();

        $this->travel(2)->minutes();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('error.code', 'SUPPORT_VIEW_ENDED');
        $this->assertSame(SupportSession::ENDED_BY_TIMEOUT, $view->fresh()->ended_by);
        $this->assertSame($view->getRawOriginal('expires_at'), $view->fresh()->getRawOriginal('ended_at'));
        $this->assertGuest('web');
    }

    public function test_signing_out_ends_the_view_and_is_not_the_users_logout(): void
    {
        [$view, $ticket] = $this->openView();
        $this->enter($ticket)->assertOk();

        $this->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertGuest('web');
        $this->assertSame(SupportSession::ENDED_BY_SIGN_OUT, $view->fresh()->ended_by);
        $this->assertDatabaseMissing('audit_logs', ['tenant_id' => $this->school->id, 'action' => 'LOGOUT']);
    }

    public function test_an_ordinary_session_is_untouched(): void
    {
        $this->actingAs($this->user, 'web');

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('meta.support_view', null);
        $this->postJson('/api/v1/auth/change-password', [])->assertUnprocessable(); // reaches validation, not refused
    }
}
