<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Models\Plan;
use App\Platform\Models\SchoolSubscription;
use App\Platform\Models\Signup;
use App\Platform\Services\SchoolDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\FakeSchoolDatabase;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

/**
 * Stage 8b (docs/backoffice/stage-8-signup.md): self-service sign-up.
 */
class SignupTest extends TestCase
{
    use HasPlatformStaff, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(SchoolDatabase::class, new FakeSchoolDatabase);
        Notification::fake();
        RateLimiter::clear('signups:127.0.0.1');
        config(['platform.website_school' => 'newtech']);
        Tenant::factory()->create(['slug' => 'newtech']);
        Tenant::factory()->create(['slug' => 'other']);
    }

    private function form(array $overrides = []): array
    {
        return $overrides + [
            'plan' => 'trial',
            'school_name' => 'សាលាអង្គរ',
            'school_name_en' => 'Angkor School',
            'slug' => 'angkor',
            'owner_name' => 'Sok Dara',
            'owner_email' => 'Dara@Example.com',
            'owner_phone' => '012 345 678',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
            'locale' => 'km',
        ];
    }

    private function signUp(array $overrides = [])
    {
        return $this->withHeader('X-Tenant', 'newtech')->postJson('/api/v1/public/signup', $this->form($overrides));
    }

    private function plan(array $overrides = []): Plan
    {
        return Plan::query()->create($overrides + [
            'code' => 'starter', 'name' => 'Starter', 'price' => 15, 'currency' => 'USD', 'period_months' => 1,
            'modules' => ['academics'], 'status' => Plan::STATUS_ACTIVE, 'show_on_website' => true,
        ]);
    }

    public function test_a_free_trial_creates_the_school_with_the_owners_own_password_and_no_invite(): void
    {
        $token = $this->signUp()->assertCreated()
            ->assertJsonPath('data.status', 'created')
            ->assertJsonPath('data.school_status', 'active')
            ->json('data.token');

        $tenant = Tenant::query()->where('slug', 'angkor')->firstOrFail();
        $this->assertSame('Angkor School', $tenant->name_en);
        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->status);
        $this->assertSame(SchoolSubscription::TRIAL, SchoolSubscription::query()->where('tenant_id', $tenant->id)->value('status'));

        $owner = User::query()->inTenant($tenant->id)->where('email', 'dara@example.com')->firstOrFail();
        $this->assertSame(User::STATUS_ACTIVE, $owner->status);
        $this->assertTrue(Hash::check('secret-pass-1', $owner->password));
        Notification::assertNothingSent();

        $this->assertNull(Signup::query()->where('token', $token)->value('owner_password'));
        $this->withHeader('X-Tenant', 'newtech')->getJson("/api/v1/public/signup/{$token}")->assertOk()
            ->assertJsonPath('data.sign_in_url', rtrim((string) config('platform.console_url'), '/').'/login?tenant=angkor')
            ->assertJsonMissingPath('data.owner_password');
        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'school.signed_up', 'tenant_id' => $tenant->id, 'staff_id' => null]);
    }

    public function test_one_free_trial_per_email(): void
    {
        $this->signUp()->assertCreated();

        $this->signUp(['slug' => 'angkor-two'])->assertUnprocessable()->assertJsonValidationErrors('owner_email');
    }

    public function test_a_paid_plan_waits_for_payment_and_holds_its_web_address(): void
    {
        $this->plan();

        $this->signUp(['plan' => 'starter', 'periods' => 3])->assertCreated()
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.amount', '45.00')
            ->assertJsonPath('data.school_status', null);
        $this->assertFalse(Tenant::query()->where('slug', 'angkor')->exists());

        // Someone else cannot take it meanwhile; the same person can start again.
        $this->withHeader('X-Tenant', 'newtech')->getJson('/api/v1/public/signup/slug?slug=angkor')->assertJsonPath('data.available', false);
        $this->signUp(['plan' => 'starter', 'owner_email' => 'someone@else.com'])->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->signUp(['plan' => 'starter', 'periods' => 1])->assertCreated()->assertJsonPath('data.amount', '15.00');
        $this->assertSame(1, Signup::query()->where('status', Signup::PENDING_PAYMENT)->count());

        // After the hold, it is free again.
        $this->travel(Signup::HOLD_MINUTES + 1)->minutes();
        $this->withHeader('X-Tenant', 'newtech')->getJson('/api/v1/public/signup/slug?slug=angkor')->assertJsonPath('data.available', true);
    }

    public function test_hidden_or_legacy_plans_cannot_be_bought(): void
    {
        $this->plan(['code' => 'hidden', 'show_on_website' => false]);

        $this->signUp(['plan' => 'hidden'])->assertUnprocessable()->assertJsonValidationErrors('plan');
        $this->signUp(['plan' => Plan::LEGACY])->assertUnprocessable()->assertJsonValidationErrors('plan');
    }

    public function test_web_address_rules(): void
    {
        $check = fn (string $slug) => $this->withHeader('X-Tenant', 'newtech')->getJson('/api/v1/public/signup/slug?slug='.$slug)->json('data.available');

        $this->assertTrue($check('my-school'));
        $this->assertFalse($check('ab'));
        $this->assertFalse($check('Bad Slug'));
        $this->assertFalse($check('admin'));
        $this->assertFalse($check('other'));
        $this->signUp(['slug' => 'www'])->assertUnprocessable()->assertJsonValidationErrors('slug');
    }

    public function test_bots_and_bad_input_are_turned_away(): void
    {
        $this->signUp(['website' => 'http://spam'])->assertUnprocessable()->assertJsonPath('error.code', 'SIGNUP_REJECTED');
        $this->signUp(['password_confirmation' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->signUp(['owner_phone' => 'call me'])->assertUnprocessable()->assertJsonValidationErrors('owner_phone');
        $this->assertSame(0, Signup::query()->count());
    }

    public function test_only_the_website_school_takes_sign_ups(): void
    {
        $this->withHeader('X-Tenant', 'other')->postJson('/api/v1/public/signup', $this->form())->assertNotFound();
        $this->withHeader('X-Tenant', 'other')->getJson('/api/v1/public/signup/slug?slug=abc')->assertNotFound();
    }

    public function test_staff_see_sign_ups_in_the_back_office(): void
    {
        $this->plan();
        $this->signUp(['plan' => 'starter'])->assertCreated();

        $this->actingAsStaff($this->platformStaff(['platform.billing.read']));
        $this->getJson('/api/v1/platform/billing/signups')->assertOk()
            ->assertJsonPath('data.0.slug', 'angkor')
            ->assertJsonPath('data.0.status', 'pending_payment')
            ->assertJsonPath('data.0.plan.code', 'starter')
            ->assertJsonMissingPath('data.0.owner_password');

        $this->actingAsStaff($this->platformStaff([]));
        $this->getJson('/api/v1/platform/billing/signups')->assertNotFound();
    }
}
