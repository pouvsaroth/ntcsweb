<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Auth\SchoolOwnerInviteNotification;
use App\Platform\Models\School;
use App\Platform\Models\SchoolProvisioningLog;
use App\Platform\Services\SchoolDatabase;
use App\Platform\Support\DnsTxtLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\FakeSchoolDatabase;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

class PlatformSchoolsTest extends TestCase
{
    use HasPlatformStaff, RefreshDatabase;

    private FakeSchoolDatabase $database;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = new FakeSchoolDatabase;
        $this->app->instance(SchoolDatabase::class, $this->database);
        Notification::fake();
    }

    private function createSchool(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/platform/schools', array_merge([
            'name' => 'សាលាអង្គរ',
            'name_en' => 'Angkor School',
            'slug' => 'angkor',
            'owner_name' => 'Sok Dara',
            'owner_email' => 'Dara@Angkor.edu.kh',
        ], $overrides));
    }

    /** The link in the invite mail, as the owner would click it. */
    private function inviteToken(User $owner): string
    {
        $token = null;
        Notification::assertSentTo($owner, SchoolOwnerInviteNotification::class, function ($n) use ($owner, &$token) {
            parse_str((string) parse_url($n->toMail($owner)->actionUrl, PHP_URL_QUERY), $query);
            $token = $query['token'];

            return true;
        });

        return $token;
    }

    public function test_creating_a_school_provisions_it_and_invites_the_owner(): void
    {
        $staff = $this->platformStaff();
        $id = $this->actingAsStaff($staff)->createSchool()->assertCreated()->json('data.id');

        // The job ran (sync queue in tests) after the request committed.
        $tenant = Tenant::query()->findOrFail($id);
        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->status);
        $this->assertSame(['angkor'], $this->database->created);

        $school = School::query()->findOrFail($id);
        $this->assertNotNull($school->activated_at);
        $this->assertSame($staff->id, $school->created_by);

        $steps = SchoolProvisioningLog::query()->where('tenant_id', $id)->orderBy('id')->pluck('status', 'step')->all();
        $this->assertSame(['database' => 'ok', 'seed' => 'ok', 'health' => 'ok', 'activate' => 'ok', 'owner' => 'ok'], $steps);

        $owner = $school->owner;
        $this->assertSame('dara@angkor.edu.kh', $owner->email);
        $this->assertSame(User::STATUS_INVITED, $owner->status);
        $this->assertTrue($owner->roles->contains('slug', Role::SCHOOL_ADMIN));
        $this->assertSame(4, Role::query()->withoutGlobalScopes()->where('tenant_id', $id)->count());
        $this->assertNotNull($school->invite_sent_at);
        Notification::assertSentTo($owner, SchoolOwnerInviteNotification::class);

        foreach (['school.created', 'school.owner_invited'] as $action) {
            $this->assertDatabaseHas('bo_audit_logs', ['action' => $action, 'tenant_id' => $id]);
        }
        $this->assertDatabaseHas('bo_school_status_history', ['tenant_id' => $id, 'from_status' => 'provisioning', 'to_status' => 'active']);

        $this->getJson("/api/v1/platform/schools/{$id}")->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.database', config('tenancy.database.shared_database')) // shared is the default placement (stage 4)
            ->assertJsonPath('data.owner.accepted', false)
            ->assertJsonCount(5, 'data.provisioning_log');
    }

    public function test_a_failed_step_marks_the_school_failed_and_retry_finishes_it(): void
    {
        $this->database->failTimes = 1;
        $this->actingAsStaff($this->platformStaff());

        $id = $this->createSchool()->assertCreated()->json('data.id');

        $this->getJson("/api/v1/platform/schools/{$id}")->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.status_detail', 'could not connect to server: Connection refused')
            ->assertJsonPath('data.provisioning_log.0.step', 'database')
            ->assertJsonPath('data.provisioning_log.0.status', 'failed');
        Notification::assertNothingSent();

        $this->postJson("/api/v1/platform/schools/{$id}/retry")->assertOk();

        $this->getJson("/api/v1/platform/schools/{$id}")->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.status_detail', null)
            ->assertJsonPath('data.provisioning_attempts', 2)
            ->assertJsonCount(5, 'data.provisioning_log');

        // Retry is only for a failed school.
        $this->postJson("/api/v1/platform/schools/{$id}/retry")->assertUnprocessable();
    }

    public function test_the_slug_is_checked_and_can_never_change(): void
    {
        Tenant::factory()->create(['slug' => 'taken']);
        $this->database->existing = ['tn_leftover'];
        $this->actingAsStaff($this->platformStaff());

        foreach (['A', 'Bad_Slug', '-dash', 'www', 'admin', 'taken', 'leftover'] as $slug) {
            $this->createSchool(['slug' => $slug])->assertUnprocessable()->assertJsonValidationErrors('slug');
        }

        $id = $this->createSchool()->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/platform/schools/{$id}", ['slug' => 'other'])->assertUnprocessable();
        $this->patchJson("/api/v1/platform/schools/{$id}", ['name_en' => 'Angkor High'])->assertOk()
            ->assertJsonPath('data.name_en', 'Angkor High')
            ->assertJsonPath('data.slug', 'angkor');
    }

    public function test_the_owner_sets_their_own_password_from_the_invite_and_a_resend_kills_the_old_link(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $id = $this->createSchool()->json('data.id');
        $owner = School::query()->findOrFail($id)->owner;
        $first = $this->inviteToken($owner);

        Notification::fake();
        $this->postJson("/api/v1/platform/schools/{$id}/owner/invite")->assertOk();
        $second = $this->inviteToken($owner);

        $accept = fn (string $token) => $this->withHeader('X-Tenant', 'angkor')->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $owner->email,
            'password' => 'my-own-secret-1',
            'password_confirmation' => 'my-own-secret-1',
        ]);

        $accept($first)->assertUnprocessable();
        $accept($second)->assertOk();

        $owner->refresh();
        $this->assertSame(User::STATUS_ACTIVE, $owner->status);
        $this->assertTrue(\Hash::check('my-own-secret-1', $owner->password));

        // Once accepted there is nothing to resend.
        $this->postJson("/api/v1/platform/schools/{$id}/owner/invite")->assertUnprocessable();
    }

    /** When email is not an option, staff copy a link and send it another way (Telegram, SMS…). */
    public function test_a_copied_invite_link_works_is_not_emailed_and_retires_the_previous_one(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $id = $this->createSchool()->json('data.id');
        $owner = School::query()->findOrFail($id)->owner;
        $emailed = $this->inviteToken($owner);

        Notification::fake();
        $url = $this->postJson("/api/v1/platform/schools/{$id}/owner/invite-link")->assertOk()
            ->assertJsonPath('data.expires_in_days', 7)
            ->json('data.url');
        Notification::assertNothingSent();

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('angkor', $query['tenant']);
        $this->assertSame('1', $query['invite']);
        $this->assertStringContainsString('/reset-password?', $url);

        $accept = fn (string $token) => $this->withHeader('X-Tenant', 'angkor')->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $owner->email,
            'password' => 'my-own-secret-1',
            'password_confirmation' => 'my-own-secret-1',
        ]);

        $accept($emailed)->assertUnprocessable();
        $accept($query['token'])->assertOk();
        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'school.owner_invite_link_copied', 'tenant_id' => $id]);

        // Accepted: no more links.
        $this->postJson("/api/v1/platform/schools/{$id}/owner/invite-link")->assertUnprocessable();
    }

    /**
     * Production opens invite links on the shared console, where schools are
     * NOT resolved from the request (allow_request_resolution off). Found
     * live on 2026-10-16: every owner's "set password" answered "invalid or
     * expired". The link's own tenant= now names the school for the check.
     */
    public function test_an_invite_link_works_on_the_console_where_schools_are_not_resolved_from_the_request(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $id = $this->createSchool()->json('data.id');
        $owner = School::query()->findOrFail($id)->owner;
        $token = $this->inviteToken($owner);
        Tenant::factory()->create(['slug' => 'other-school']);

        config(['tenancy.allow_request_resolution' => false]);
        app(\App\Support\Tenancy\TenantContext::class)->forget();

        $submit = fn (string $school) => $this->postJson('http://localhost/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $owner->email,
            'tenant' => $school,
            'password' => 'my-own-secret-1',
            'password_confirmation' => 'my-own-secret-1',
        ]);

        // The token belongs to angkor: naming another school does nothing.
        $submit('other-school')->assertUnprocessable();
        $this->assertSame(User::STATUS_INVITED, $owner->fresh()->status);

        $submit('angkor')->assertOk();
        $this->assertSame(User::STATUS_ACTIVE, $owner->fresh()->status);
    }

    public function test_an_invite_link_lasts_seven_days_not_sixty_minutes(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $id = $this->createSchool()->json('data.id');
        $owner = School::query()->findOrFail($id)->owner;
        $token = $this->inviteToken($owner);

        $this->travel(6)->days();

        $this->withHeader('X-Tenant', 'angkor')->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $owner->email,
            'password' => 'my-own-secret-1',
            'password_confirmation' => 'my-own-secret-1',
        ])->assertOk();
    }

    public function test_changing_the_owner_invites_the_new_person_and_leaves_the_old_account(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $id = $this->createSchool()->json('data.id');
        $previous = School::query()->findOrFail($id)->owner;

        $this->putJson("/api/v1/platform/schools/{$id}/owner", ['name' => 'Chan Vanna', 'email' => 'vanna@angkor.edu.kh'])->assertOk();

        $owner = School::query()->findOrFail($id)->owner;
        $this->assertSame('vanna@angkor.edu.kh', $owner->email);
        $this->assertTrue($owner->roles->contains('slug', Role::SCHOOL_ADMIN));
        Notification::assertSentTo($owner, SchoolOwnerInviteNotification::class);
        $this->assertNotNull($previous->fresh());
        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'school.owner_changed', 'tenant_id' => $id]);
    }

    public function test_suspend_reactivate_archive_are_recorded_and_archived_is_final(): void
    {
        $staff = $this->platformStaff();
        $this->actingAsStaff($staff);
        $id = $this->createSchool()->json('data.id');

        $this->postJson("/api/v1/platform/schools/{$id}/suspend")->assertUnprocessable(); // reason required
        $this->postJson("/api/v1/platform/schools/{$id}/suspend", ['reason' => 'Unpaid'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->postJson("/api/v1/platform/schools/{$id}/reactivate")->assertOk()->assertJsonPath('data.status', 'active');
        $this->postJson("/api/v1/platform/schools/{$id}/archive", ['reason' => 'Closed'])->assertOk()->assertJsonPath('data.status', 'archived');
        $this->postJson("/api/v1/platform/schools/{$id}/reactivate")->assertUnprocessable();

        $this->assertDatabaseHas('bo_school_status_history', ['tenant_id' => $id, 'to_status' => 'suspended', 'reason' => 'Unpaid', 'staff_id' => $staff->id]);
        $this->assertSame(4, \DB::table('bo_audit_logs')->where('tenant_id', $id)->where('action', 'school.status_changed')->count());

        // Archived schools leave the default list but stay findable.
        $this->getJson('/api/v1/platform/schools')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/platform/schools?filter[status]=archived')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_school_that_is_not_active_answers_404_and_its_users_are_refused(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $id = $this->createSchool()->json('data.id');
        $tenant = Tenant::query()->findOrFail($id);
        $user = User::factory()->forTenant($tenant)->create();

        $this->withHeader('X-Tenant', 'angkor')->getJson('/api/v1/public/home-slides')->assertOk();

        $this->postJson("/api/v1/platform/schools/{$id}/suspend", ['reason' => 'Unpaid'])->assertOk();

        // Each real request starts with no school in context; the test app is
        // one long-lived process, so start this one fresh the same way.
        app(\App\Support\Tenancy\TenantContext::class)->forget();
        $this->withHeader('X-Tenant', 'angkor')->getJson('/api/v1/public/home-slides')->assertNotFound();
        $this->actingAs($user, 'web')->getJson('/api/v1/auth/me')->assertForbidden();
    }

    public function test_a_custom_domain_resolves_only_after_its_txt_record_is_found(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $id = $this->createSchool()->json('data.id');

        $this->postJson("/api/v1/platform/schools/{$id}/domains", ['hostname' => 'angkor.'.config('tenancy.root_domain')])->assertUnprocessable();
        $this->postJson("/api/v1/platform/schools/{$id}/domains", ['hostname' => 'localhost'])->assertUnprocessable();
        $domainId = $this->postJson("/api/v1/platform/schools/{$id}/domains", ['hostname' => 'WWW.Angkor.edu.kh'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/platform/schools/{$id}/domains", ['hostname' => 'www.angkor.edu.kh'])->assertUnprocessable();

        $domain = $this->getJson("/api/v1/platform/schools/{$id}")->json('data.domains.0');
        $this->assertSame('www.angkor.edu.kh', $domain['hostname']);
        $this->assertFalse($domain['verified']);
        $this->assertSame('_ntcsweb-verify.www.angkor.edu.kh', $domain['txt_name']);

        app(\App\Support\Tenancy\TenantContext::class)->forget();
        $this->get('http://www.angkor.edu.kh/api/v1/public/home-slides', ['Accept' => 'application/json'])->assertNotFound();
        $this->postJson("/api/v1/platform/schools/{$id}/domains/{$domainId}/primary")->assertUnprocessable();

        $records = [];
        $this->app->instance(DnsTxtLookup::class, new class($records) extends DnsTxtLookup
        {
            public function __construct(public array &$records) {}

            public function txt(string $hostname): array
            {
                return $this->records[$hostname] ?? [];
            }
        });

        $this->postJson("/api/v1/platform/schools/{$id}/domains/{$domainId}/verify")->assertOk()->assertJsonPath('data.verified', false);

        $records['_ntcsweb-verify.www.angkor.edu.kh'] = [$domain['txt_value']];
        $this->postJson("/api/v1/platform/schools/{$id}/domains/{$domainId}/verify")->assertOk()->assertJsonPath('data.verified', true);

        \Cache::store(config('tenancy.cache.store'))->flush();
        app(\App\Support\Tenancy\TenantContext::class)->forget();
        $this->get('http://www.angkor.edu.kh/api/v1/public/home-slides', ['Accept' => 'application/json'])->assertOk();

        $this->postJson("/api/v1/platform/schools/{$id}/domains/{$domainId}/primary")->assertOk();
        $this->deleteJson("/api/v1/platform/schools/{$id}/domains/{$domainId}")->assertNoContent();
        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'school.domain_verified', 'tenant_id' => $id]);
    }

    public function test_school_permissions_are_separate(): void
    {
        $reader = $this->platformStaff(['platform.school.read']);
        $this->actingAsStaff($reader);

        $this->getJson('/api/v1/platform/schools')->assertOk();
        $this->createSchool()->assertNotFound();

        $tenant = Tenant::factory()->create();
        $this->postJson("/api/v1/platform/schools/{$tenant->id}/suspend", ['reason' => 'x'])->assertNotFound();
    }

    public function test_the_pending_command_requeues_stuck_schools(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'stuck']);
        $tenant->forceFill(['status' => Tenant::STATUS_PROVISIONING])->save();
        app(\App\Platform\Services\SchoolOwners::class)->ensureAccount($tenant, 'Owner', 'owner@stuck.test');

        $this->artisan('platform:provision-pending')->expectsOutputToContain('Queued 1')->assertSuccessful();

        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->fresh()->status);
    }
}
