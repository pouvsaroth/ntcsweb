<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Role;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Models\DemoVisit;
use App\Platform\Support\SupportViewSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

/**
 * Stage 12 (docs/backoffice/stage-12-demo.md): "Try the demo" on the website.
 */
class DemoTest extends TestCase
{
    use HasPlatformStaff, RefreshDatabase;

    private Tenant $demo;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('demos:127.0.0.1');
        config(['platform.website_school' => 'newtech', 'platform.demo_school' => 'demo', 'platform.demo_days' => 7,
            'platform.console_url' => 'https://erp.example.test']);
        Tenant::factory()->create(['slug' => 'newtech']);
        $this->demo = Tenant::factory()->create(['slug' => 'demo', 'timezone' => 'Asia/Phnom_Penh']);

        app(TenantContext::class)->withoutTenancy(function () {
            foreach ([Role::SCHOOL_ADMIN => 'School Admin', Role::TEACHER => 'Teacher', Role::STUDENT => 'Student'] as $slug => $name) {
                Role::factory()->forTenant($this->demo)->system()->create(['slug' => $slug, 'name' => $name, 'level' => Role::LEVELS[$slug]]);
            }
        });

        $this->withHeader('Origin', 'http://localhost');
    }

    /** The website is another site than the console: no console sign-in comes along. */
    private function freshBrowser(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    private function start(array $overrides = [], string $site = 'newtech'): TestResponse
    {
        $this->freshBrowser();

        return $this->withHeader('X-Tenant', $site)->postJson('/api/v1/public/demo', $overrides + [
            'name' => 'Sok Dara', 'phone' => '012 345 678', 'email' => 'Dara@Example.com', 'role' => 'teacher', 'locale' => 'km',
        ]);
    }

    private function ticketFrom(TestResponse $response): string
    {
        parse_str((string) parse_url($response->json('data.sign_in_url'), PHP_URL_QUERY), $query);
        $this->assertSame('demo', $query['tenant']);

        return $query['ticket'];
    }

    private function enter(string $ticket, string $tenant = 'demo'): TestResponse
    {
        return $this->withHeader('X-Tenant', $tenant)->postJson('/api/v1/auth/demo', ['ticket' => $ticket]);
    }

    private function inDemo(callable $callback): mixed
    {
        $context = app(TenantContext::class);
        $context->set($this->demo);

        try {
            return $callback();
        } finally {
            $context->forget();
        }
    }

    public function test_a_teacher_demo_makes_an_account_a_staff_record_and_a_back_office_row(): void
    {
        $response = $this->start()->assertCreated()->assertJsonPath('data.role', 'teacher');
        $this->assertStringStartsWith('https://erp.example.test/demo?', $response->json('data.sign_in_url'));

        $visit = DemoVisit::query()->sole();
        $this->assertSame('Sok Dara', $visit->name);
        $this->assertSame('dara@example.com', $visit->email);
        $this->assertSame($this->demo->id, $visit->tenant_id);
        // Back office rows are UTC; the website request left the school's timezone behind.
        SupportViewSession::inUtc(fn () => $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $visit->fresh()->expires_at->timestamp, 5));

        $user = User::query()->withoutGlobalScopes()->findOrFail($visit->user_id);
        $this->assertSame($this->demo->id, $user->tenant_id);
        $this->assertStringEndsWith('@demo.local', $user->email);
        $this->assertTrue($user->hasRole(Role::TEACHER));

        $staff = $this->inDemo(fn () => Staff::query()->with('position')->findOrFail($visit->staff_id));
        $this->assertSame($user->id, $staff->user_id);
        $this->assertSame('Teacher', $staff->position->name);
        $this->assertNull($visit->student_id);

        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'demo.started', 'target_id' => $visit->id, 'tenant_id' => $this->demo->id]);
    }

    public function test_a_director_gets_school_admin_and_a_student_gets_a_student_record(): void
    {
        $this->start(['role' => 'director', 'email' => null, 'phone' => null])->assertCreated();
        $director = DemoVisit::query()->where('role', 'director')->sole();
        $this->assertTrue(User::query()->withoutGlobalScopes()->findOrFail($director->user_id)->hasRole(Role::SCHOOL_ADMIN));
        $this->assertSame('School Director', $this->inDemo(fn () => Staff::query()->findOrFail($director->staff_id)->position->name));

        // The same person again, as a student: their contact details are not the login.
        $this->start(['role' => 'student'])->assertCreated();
        $student = DemoVisit::query()->where('role', 'student')->sole();
        $record = $this->inDemo(fn () => Student::query()->findOrFail($student->student_id));
        $this->assertSame($student->user_id, $record->user_id);
        $this->assertSame('Sok Dara', $record->first_name);
        $this->assertTrue(User::query()->withoutGlobalScopes()->findOrFail($student->user_id)->hasRole(Role::STUDENT));
    }

    public function test_the_ticket_signs_in_once_on_the_demo_school_only(): void
    {
        $ticket = $this->ticketFrom($this->start()->assertCreated());
        $visit = DemoVisit::query()->sole();

        $this->enter($ticket, 'newtech')->assertUnprocessable()->assertJsonValidationErrors('ticket');
        $this->assertGuest('web');

        $this->enter($ticket)->assertOk();
        $this->assertAuthenticatedAs($visit->user, 'web');
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $visit->user_id);
        $this->assertSame(1, $visit->fresh()->sign_ins);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $this->demo->id, 'action' => 'LOGIN', 'user_id' => $visit->user_id]);

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->enter($ticket)->assertUnprocessable(); // used

        $late = $this->ticketFrom($this->start());
        $this->travel(3)->minutes();
        $this->enter($late)->assertUnprocessable(); // expired
        $this->enter('not-a-ticket')->assertUnprocessable();
    }

    public function test_continue_gives_a_new_ticket_until_the_demo_ends(): void
    {
        $token = $this->start()->json('data.token');

        $this->freshBrowser();
        $again = $this->withHeader('X-Tenant', 'newtech')->postJson("/api/v1/public/demo/{$token}/enter")->assertOk();
        $this->enter($this->ticketFrom($again))->assertOk();

        $this->travel(8)->days();
        $this->artisan('platform:demos-tick')->assertSuccessful();

        $visit = DemoVisit::query()->sole();
        $this->assertNotNull($visit->ended_at);
        $this->assertSame(User::STATUS_SUSPENDED, User::query()->withoutGlobalScopes()->find($visit->user_id)->status);
        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'demo.ended', 'target_id' => $visit->id]);

        $this->freshBrowser();
        $this->withHeader('X-Tenant', 'newtech')->postJson("/api/v1/public/demo/{$token}/enter")
            ->assertUnprocessable()->assertJsonValidationErrors('demo');
    }

    public function test_only_on_the_website_school_with_valid_input(): void
    {
        Tenant::factory()->create(['slug' => 'other']);
        $this->start([], 'other')->assertNotFound();

        $this->start(['name' => '', 'role' => 'principal', 'email' => 'nope', 'phone' => 'abc'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'role', 'email', 'phone']);

        $this->start(['website' => 'http://spam'])->assertUnprocessable()->assertJsonPath('error.code', 'DEMO_REJECTED');
        $this->assertSame(0, DemoVisit::query()->count());
    }

    public function test_a_missing_or_inactive_demo_school_is_a_clear_error(): void
    {
        $this->demo->forceFill(['status' => Tenant::STATUS_SUSPENDED])->save();

        $this->start()->assertUnprocessable()->assertJsonValidationErrors('demo');
        $this->assertSame(0, DemoVisit::query()->count());
    }

    public function test_back_office_lists_demos_for_billing_readers(): void
    {
        $this->start()->assertCreated();
        $this->start(['role' => 'student', 'name' => 'Chan Mony'])->assertCreated();

        $this->actingAsStaff($this->platformStaff([]));
        $this->getJson('/api/v1/platform/billing/demos')->assertNotFound();

        $this->actingAsStaff($this->platformStaff(['platform.billing.read']));
        $this->getJson('/api/v1/platform/billing/demos')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/platform/billing/demos?filter[role]=student')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Chan Mony')->assertJsonPath('data.0.live', true);

        // Live / ended: one ended by the hourly tick.
        $this->travel(8)->days();
        $this->artisan('platform:demos-tick');
        $this->start(['name' => 'Keo Lina'])->assertCreated();
        $this->actingAsStaff($this->platformStaff(['platform.billing.read']));
        $this->getJson('/api/v1/platform/billing/demos?filter[state]=live')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Keo Lina');
        $this->getJson('/api/v1/platform/billing/demos?filter[state]=ended')->assertOk()->assertJsonCount(2, 'data');
    }
}
