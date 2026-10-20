<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Models\BillingSettings;
use App\Platform\Models\Plan;
use App\Platform\Models\SchoolPayment;
use App\Platform\Models\SchoolSubscription;
use App\Platform\Models\Signup;
use App\Platform\Services\SchoolDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\FakeSchoolDatabase;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

/**
 * Stage 8c (docs/backoffice/stage-8-signup.md): KHQR payment, looked up at Bakong (faked).
 */
class SignupPaymentTest extends TestCase
{
    use HasPlatformStaff, RefreshDatabase;

    private const TEMPLATE = '00020101021129380009khqr@aclb0111855100589230206ACLEDA391300042CCY010145204599953031165802KH5910ពៅ  សារដ្ធ6010PHNOM PENH621302090122374226304D1E3';

    private const BAKONG = 'https://api-bakong.nbc.gov.kh/v1/check_transaction_by_md5';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(SchoolDatabase::class, new FakeSchoolDatabase);
        Notification::fake();
        RateLimiter::clear('signups:127.0.0.1');
        Carbon::setTestNow(Carbon::parse('2026-10-20 03:00:00', 'UTC'));
        config(['platform.website_school' => 'newtech', 'platform.bakong.api_url' => 'https://api-bakong.nbc.gov.kh']);
        Tenant::factory()->create(['slug' => 'newtech']);
        BillingSettings::current()->update(['khqr_template' => self::TEMPLATE, 'bakong_token' => 'test-token']);
        Plan::query()->create([
            'code' => 'growth', 'name' => 'Growth', 'price' => 29, 'currency' => 'USD', 'period_months' => 1,
            'modules' => ['academics'], 'status' => Plan::STATUS_ACTIVE, 'show_on_website' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function signUp(array $overrides = []): array
    {
        return $this->withHeader('X-Tenant', 'newtech')->postJson('/api/v1/public/signup', $overrides + [
            'plan' => 'growth', 'periods' => 3, 'school_name' => 'សាលាអង្គរ', 'slug' => 'angkor',
            'owner_name' => 'Sok Dara', 'owner_email' => 'dara@example.com', 'owner_phone' => '012345678',
            'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1',
        ])->assertCreated()->json('data');
    }

    private function stateOf(string $token): array
    {
        return $this->withHeader('X-Tenant', 'newtech')->getJson("/api/v1/public/signup/{$token}")->assertOk()->json('data');
    }

    private function bakongPaid(float $amount = 87.0, string $currency = 'USD'): void
    {
        Http::fake([self::BAKONG => Http::response(['responseCode' => 0, 'responseMessage' => 'Getting transaction successfully.', 'data' => [
            'hash' => 'abc123hash', 'fromAccountId' => 'payer@aclb', 'toAccountId' => 'newtech@aclb', 'currency' => $currency, 'amount' => $amount,
        ]])]);
    }

    private function bakongNotYet(): void
    {
        Http::fake([self::BAKONG => Http::response(['responseCode' => 1, 'responseMessage' => 'Transaction could not be found.', 'errorCode' => 1, 'data' => null])]);
    }

    public function test_a_paid_sign_up_gets_its_own_khqr_for_the_exact_amount(): void
    {
        $a = $this->signUp();
        $this->assertSame('pending_payment', $a['status']);
        $this->assertStringContainsString('540587.00', $a['khqr']); // tag 54, $29 × 3
        $this->assertStringContainsString('5802KH5910ពៅ  សារដ្ធ', $a['khqr']); // the rest of the template untouched

        // Same amount, another sign-up: a different code (tag 99), so a different MD5.
        Carbon::setTestNow(now()->addSecond());
        $b = $this->signUp(['slug' => 'bayon', 'owner_email' => 'b@example.com']);
        $this->assertNotSame($a['khqr'], $b['khqr']);
        $this->assertSame(md5($a['khqr']), Signup::query()->where('slug', 'angkor')->value('khqr_md5'));
    }

    public function test_not_paid_yet_keeps_waiting(): void
    {
        $this->bakongNotYet();
        $s = $this->signUp();

        $this->assertSame('pending_payment', $this->stateOf($s['token'])['status']);
        Http::assertSent(fn ($r) => $r->url() === self::BAKONG && $r['md5'] === md5($s['khqr']) && $r->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_once_paid_the_school_is_created_active_for_the_months_bought_with_a_receipt(): void
    {
        $s = $this->signUp();
        $this->bakongPaid();

        $state = $this->stateOf($s['token']);
        $this->assertSame('created', $state['status']);
        $this->assertSame('active', $state['school_status']);
        $this->assertSame('R-2026-0001', $state['receipt_number']);

        $tenant = Tenant::query()->where('slug', 'angkor')->firstOrFail();
        $sub = SchoolSubscription::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame(SchoolSubscription::ACTIVE, $sub->status);
        $this->assertSame('2026-10-20', $sub->start_on->toDateString());
        $this->assertSame('2027-01-19', $sub->end_on->toDateString()); // 3 months

        $payment = SchoolPayment::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('khqr', $payment->method);
        $this->assertSame('abc123hash', $payment->reference);
        $this->assertSame('87.00', (string) $payment->amount);
        $this->assertNull($payment->recorded_by);

        $owner = User::query()->inTenant($tenant->id)->where('email', 'dara@example.com')->firstOrFail();
        $this->assertSame(User::STATUS_ACTIVE, $owner->status);
        Notification::assertNothingSent();
    }

    public function test_the_wrong_amount_does_not_create_a_school_and_staff_see_why(): void
    {
        $s = $this->signUp();
        $this->bakongPaid(10.0);

        $this->assertSame('failed', $this->stateOf($s['token'])['status']);
        $this->assertFalse(Tenant::query()->where('slug', 'angkor')->exists());
        $this->assertStringContainsString('expected 87.00 USD', (string) Signup::query()->value('failure'));

        $this->actingAsStaff($this->platformStaff());
        $row = $this->getJson('/api/v1/platform/billing/signups')->assertOk()->json('data.0');
        $this->assertFalse($row['can_retry']);
        $this->postJson('/api/v1/platform/billing/signups/'.$row['id'].'/retry')->assertStatus(409);
    }

    public function test_the_tick_finds_a_payment_after_the_page_was_closed_and_expires_unpaid_ones(): void
    {
        $paid = $this->signUp();
        $this->signUp(['slug' => 'bayon', 'owner_email' => 'b@example.com']);
        Signup::query()->where('slug', 'bayon')->update(['khqr_md5' => md5('never paid')]);

        Http::fake([self::BAKONG => fn ($r) => $r['md5'] === md5($paid['khqr'])
            ? Http::response(['responseCode' => 0, 'data' => ['hash' => 'h1', 'currency' => 'USD', 'amount' => 87]])
            : Http::response(['responseCode' => 1, 'data' => null])]);

        $this->artisan('platform:signups-tick')->assertSuccessful();
        $this->assertSame(Signup::CREATED, Signup::query()->where('slug', 'angkor')->value('status'));
        $this->assertSame(Signup::PENDING_PAYMENT, Signup::query()->where('slug', 'bayon')->value('status'));

        // Past the hold and the late window: closed, and its address is free again.
        Carbon::setTestNow(now()->addMinutes(Signup::HOLD_MINUTES + (int) config('platform.bakong.late_minutes') + 1));
        $this->artisan('platform:signups-tick')->assertSuccessful();
        $this->assertSame(Signup::EXPIRED, Signup::query()->where('slug', 'bayon')->value('status'));
        $this->assertNull(Signup::query()->where('slug', 'bayon')->value('owner_password'));
    }

    public function test_bakong_is_asked_at_most_every_few_seconds_per_sign_up(): void
    {
        $this->bakongNotYet();
        $s = $this->signUp();

        $this->stateOf($s['token']);
        $this->stateOf($s['token']);
        Http::assertSentCount(1);

        Carbon::setTestNow(now()->addSeconds(5));
        $this->stateOf($s['token']);
        Http::assertSentCount(2);
    }

    public function test_bakong_down_or_token_refused_keeps_the_sign_up_waiting(): void
    {
        Http::fake([self::BAKONG => Http::response(['error' => 'Unauthorized'], 401)]);
        $s = $this->signUp();

        $this->assertSame('pending_payment', $this->stateOf($s['token'])['status']);
    }

    public function test_without_online_payment_set_up_there_is_no_code(): void
    {
        BillingSettings::current()->update(['khqr_template' => null]);

        $this->assertNull($this->signUp()['khqr']);
    }

    public function test_staff_set_the_khqr_and_token_and_the_token_is_never_shown(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $jwt = 'x.'.rtrim(strtr(base64_encode(json_encode(['exp' => 1798761600])), '+/', '-_'), '=').'.y';

        $this->putJson('/api/v1/platform/billing/settings/payment', ['khqr_template' => 'not a code'])->assertUnprocessable()->assertJsonValidationErrors('khqr_template');
        $this->putJson('/api/v1/platform/billing/settings/payment', ['khqr_template' => self::TEMPLATE, 'bakong_token' => $jwt])->assertOk()
            ->assertJsonPath('data.bakong_token_set', true)
            ->assertJsonPath('data.bakong_token_expires_at', '2027-01-01T00:00:00+00:00')
            ->assertJsonMissingPath('data.bakong_token');
        $this->assertSame($jwt, BillingSettings::current()->bakong_token);

        // An empty token field keeps it; clear_token removes it.
        $this->putJson('/api/v1/platform/billing/settings/payment', ['bakong_token' => ''])->assertJsonPath('data.bakong_token_set', true);
        $this->putJson('/api/v1/platform/billing/settings/payment', ['clear_token' => true])->assertJsonPath('data.bakong_token_set', false);
        $this->assertStringNotContainsString($jwt, (string) \DB::connection(config('tenancy.database.central_connection'))->table('bo_audit_logs')->pluck('new_values')->implode(' '));
    }
}
