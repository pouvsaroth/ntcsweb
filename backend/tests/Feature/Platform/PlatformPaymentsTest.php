<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Tenant;
use App\Platform\Billing\PlatformReceiptPdf;
use App\Platform\Models\BillingSettings;
use App\Platform\Models\Plan;
use App\Platform\Models\SchoolPayment;
use App\Platform\Models\SchoolSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

/**
 * Stage 5b (docs/backoffice/stage-5-billing.md): payments and receipts.
 */
class PlatformPaymentsTest extends TestCase
{
    use HasPlatformStaff, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-18 05:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function plan(array $overrides = []): Plan
    {
        return Plan::query()->create($overrides + [
            'code' => 'basic-'.fake()->unique()->numberBetween(1, 99999),
            'name' => 'Basic', 'price' => 50, 'currency' => 'USD', 'period_months' => 1,
            'max_students' => 300, 'modules' => ['academics'], 'status' => Plan::STATUS_ACTIVE,
        ]);
    }

    private function subscribed(string $status = 'active', ?string $end = '2026-11-17', ?Plan $plan = null, string $start = '2026-10-18'): Tenant
    {
        $tenant = Tenant::factory()->create();
        SchoolSubscription::query()->create([
            'tenant_id' => $tenant->id, 'plan_id' => ($plan ?? $this->plan())->id, 'status' => $status,
            'start_on' => $start, 'end_on' => $end,
        ]);

        return $tenant;
    }

    private function pay(Tenant $tenant, array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v1/platform/schools/{$tenant->id}/billing/payments", $body + ['periods' => 1, 'method' => 'cash']);
    }

    public function test_a_payment_extends_an_active_plan_from_the_day_after_its_end(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $school = $this->subscribed('active', '2026-11-17');

        $this->pay($school, ['periods' => 3, 'method' => 'bank', 'reference' => 'ABA 123'])
            ->assertCreated()
            ->assertJsonPath('data.receipt_number', 'R-2026-0001')
            ->assertJsonPath('data.amount', '150.00') // 3 × $50 by default
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.covered_from', '2026-11-18')
            ->assertJsonPath('data.covered_to', '2027-02-17');

        $this->getJson("/api/v1/platform/schools/{$school->id}/billing")
            ->assertJsonPath('data.subscription.end_on', '2027-02-17')
            ->assertJsonPath('data.subscription.status', 'active');
        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'billing.payment_recorded', 'tenant_id' => $school->id]);
    }

    public function test_a_paid_trial_becomes_active_from_the_payment_date(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $school = $this->subscribed('trial', '2026-11-01', start: '2026-10-10');

        $this->pay($school, ['amount' => 40])
            ->assertCreated()
            ->assertJsonPath('data.amount', '40.00') // staff may record a discount
            ->assertJsonPath('data.covered_from', '2026-10-18')
            ->assertJsonPath('data.covered_to', '2026-11-17');

        $this->assertSame(SchoolSubscription::ACTIVE, SchoolSubscription::query()->where('tenant_id', $school->id)->value('status'));
    }

    public function test_an_expired_plan_is_paid_from_where_it_ended_and_becomes_active(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $school = $this->subscribed('expired', '2026-10-10');

        $this->pay($school)->assertCreated()
            ->assertJsonPath('data.covered_from', '2026-10-11')
            ->assertJsonPath('data.covered_to', '2026-11-10');

        $this->getJson("/api/v1/platform/schools/{$school->id}/billing")->assertJsonPath('data.subscription.status', 'active');
    }

    public function test_a_plan_with_no_period_records_the_payment_without_an_end(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $school = $this->subscribed('trial', '2026-11-01', $this->plan(['period_months' => null, 'price' => 500]));

        $this->pay($school)->assertCreated()
            ->assertJsonPath('data.amount', '500.00')
            ->assertJsonPath('data.covered_to', null);

        $this->getJson("/api/v1/platform/schools/{$school->id}/billing")
            ->assertJsonPath('data.subscription.status', 'active')
            ->assertJsonPath('data.subscription.end_on', '2026-11-01');
    }

    public function test_a_school_on_no_plan_cannot_be_paid_for(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $school = Tenant::factory()->create();

        $this->pay($school)->assertUnprocessable()->assertJsonValidationErrors('subscription');
        $this->getJson("/api/v1/platform/schools/{$school->id}/billing/payment-quote")->assertNotFound();
    }

    public function test_receipt_numbers_run_per_year(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $a = $this->subscribed();
        $b = $this->subscribed();

        $this->pay($a)->assertJsonPath('data.receipt_number', 'R-2026-0001');
        $this->pay($b)->assertJsonPath('data.receipt_number', 'R-2026-0002');
        $this->pay($a, ['paid_on' => '2025-12-30'])->assertJsonPath('data.receipt_number', 'R-2025-0001');
    }

    public function test_the_quote_shows_the_default_amount_and_coverage(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $school = $this->subscribed('active', '2026-11-17');

        $this->getJson("/api/v1/platform/schools/{$school->id}/billing/payment-quote?periods=12")->assertOk()
            ->assertJsonPath('data.amount', '600.00')
            ->assertJsonPath('data.covered_from', '2026-11-18')
            ->assertJsonPath('data.covered_to', '2027-11-17');
    }

    public function test_voiding_keeps_the_row_and_the_end_date(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $school = $this->subscribed('active', '2026-11-17');
        $id = $this->pay($school)->json('data.id');

        $this->postJson("/api/v1/platform/billing/payments/{$id}/void", [])->assertUnprocessable();
        $this->postJson("/api/v1/platform/billing/payments/{$id}/void", ['reason' => 'Typed the wrong school'])
            ->assertOk()->assertJsonPath('data.void', true)->assertJsonPath('data.void_reason', 'Typed the wrong school');
        $this->postJson("/api/v1/platform/billing/payments/{$id}/void", ['reason' => 'again'])->assertUnprocessable();

        $this->assertNotNull(SchoolPayment::query()->find($id));
        $this->getJson("/api/v1/platform/schools/{$school->id}/billing")->assertJsonPath('data.subscription.end_on', '2026-12-17');
    }

    public function test_payments_are_listed_and_filtered_by_school(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $a = $this->subscribed();
        $b = $this->subscribed();
        $this->pay($a);
        $this->pay($b, ['method' => 'khqr']);

        $this->getJson('/api/v1/platform/billing/payments')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/platform/billing/payments?filter[tenant_id]={$a->id}")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.school.id', $a->id)
            ->assertJsonPath('data.0.plan', 'Basic');
        $this->getJson('/api/v1/platform/billing/payments?filter[method]=khqr')->assertJsonCount(1, 'data');
    }

    public function test_the_receipt_shows_the_header_school_and_coverage_and_void_mark(): void
    {
        $this->actingAsStaff($this->platformStaff());
        BillingSettings::current()->update(['company_name' => 'New Tech Co., Ltd.', 'company_tax_number' => 'K001-9']);
        $school = $this->subscribed('active', '2026-11-17');
        $payment = SchoolPayment::query()->findOrFail($this->pay($school, ['reference' => 'ABA 77'])->json('data.id'));

        $html = app(PlatformReceiptPdf::class)->html($payment);
        $this->assertStringContainsString('New Tech Co., Ltd.', $html);
        $this->assertStringContainsString('VATTIN: K001-9', $html);
        $this->assertStringContainsString('R-2026-0001', $html);
        $this->assertStringContainsString(e($school->name), $html); // escaped, as Blade prints it
        $this->assertStringContainsString('18-11-2026 → 17-12-2026', $html);
        $this->assertStringContainsString('ABA 77', $html);
        $this->assertStringNotContainsString('class="void"', $html);

        $payment->forceFill(['voided_at' => now(), 'void_reason' => 'Wrong school'])->save();
        $this->assertStringContainsString('class="void"', app(PlatformReceiptPdf::class)->html($payment->fresh()));
    }

    public function test_the_receipt_downloads_as_a_pdf(): void
    {
        if (! file_exists(config('services.browsershot.chrome_path') ?: '/usr/bin/chromium')) {
            $this->markTestSkipped('Chromium is not available in this environment — see docker/php/Dockerfile.');
        }
        $this->actingAsStaff($this->platformStaff());
        $id = $this->pay($this->subscribed())->json('data.id');

        $response = $this->get("/api/v1/platform/billing/payments/{$id}/receipt")->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'billing.receipt_downloaded']);
    }

    public function test_staff_upload_and_remove_the_receipt_logo(): void
    {
        Storage::fake('local');
        $this->actingAsStaff($this->platformStaff());

        $this->postJson('/api/v1/platform/billing/settings/logo', ['logo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertUnprocessable();

        $path = $this->postJson('/api/v1/platform/billing/settings/logo', ['logo' => UploadedFile::fake()->image('logo.png', 120, 120)])
            ->assertOk()->json('data.company_logo');
        Storage::disk('local')->assertExists($path);
        $this->assertStringStartsWith('data:image/png;base64,', $this->getJson('/api/v1/platform/billing/settings')->json('data.company_logo_data'));

        $this->deleteJson('/api/v1/platform/billing/settings/logo')->assertOk()->assertJsonPath('data.company_logo', null);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_read_only_staff_cannot_record_or_void(): void
    {
        $school = $this->subscribed();
        $this->actingAsStaff($this->platformStaff(['platform.billing.read']));

        $this->getJson('/api/v1/platform/billing/payments')->assertOk();
        $this->pay($school)->assertNotFound();
        $this->assertSame(0, SchoolPayment::query()->count());
    }
}
