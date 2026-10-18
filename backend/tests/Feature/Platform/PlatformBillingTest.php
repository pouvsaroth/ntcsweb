<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Tenant;
use App\Platform\Billing\Modules;
use App\Platform\Models\BillingSettings;
use App\Platform\Models\Plan;
use App\Platform\Models\SchoolSubscription;
use App\Platform\Services\SchoolDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\FakeSchoolDatabase;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

/**
 * Stage 5a (docs/backoffice/stage-5-billing.md): plans, subscriptions and the
 * billing settings.
 */
class PlatformBillingTest extends TestCase
{
    use HasPlatformStaff, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SchoolDatabase::class, new FakeSchoolDatabase);
        Notification::fake();
        // Noon in Phnom Penh: "today" is the same day in UTC and locally.
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
            'name' => 'Basic',
            'price' => 50,
            'currency' => 'USD',
            'period_months' => 1,
            'max_students' => 300,
            'modules' => ['academics', 'finance'],
            'status' => Plan::STATUS_ACTIVE,
        ]);
    }

    private function school(): Tenant
    {
        return Tenant::factory()->create();
    }

    private function onPlan(Tenant $tenant, Plan $plan, string $status = 'active', ?string $end = '2026-11-17'): SchoolSubscription
    {
        return SchoolSubscription::query()->create([
            'tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => $status,
            'start_on' => '2026-10-18', 'end_on' => $end,
        ]);
    }

    public function test_the_legacy_plan_holds_every_module_with_no_limit_and_no_end(): void
    {
        $legacy = Plan::legacy();

        $this->assertSame(Modules::codes(), $legacy->modules);
        $this->assertNull($legacy->max_students);
        $this->assertNull($legacy->period_months);
        $this->assertSame('0.00', $legacy->price);
    }

    public function test_a_new_school_starts_on_a_trial_for_the_settings_trial_days(): void
    {
        $this->actingAsStaff($this->platformStaff());

        $id = $this->postJson('/api/v1/platform/schools', [
            'name' => 'Angkor', 'slug' => 'angkor', 'owner_name' => 'Dara', 'owner_email' => 'dara@angkor.test',
        ])->assertCreated()
            ->assertJsonPath('data.billing.subscription.status', 'trial')
            ->assertJsonPath('data.billing.subscription.plan.code', 'legacy')
            ->assertJsonPath('data.billing.subscription.end_on', '2026-11-01') // 15 days, today included
            ->json('data.id');

        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'billing.trial_started', 'tenant_id' => $id]);
    }

    public function test_the_trial_uses_the_plan_and_days_chosen_in_settings(): void
    {
        $plan = $this->plan(['code' => 'starter']);
        BillingSettings::current()->update(['trial_days' => 30, 'trial_plan_id' => $plan->id]);
        $this->actingAsStaff($this->platformStaff());

        $this->postJson('/api/v1/platform/schools', [
            'name' => 'Angkor', 'slug' => 'angkor', 'owner_name' => 'Dara', 'owner_email' => 'dara@angkor.test',
        ])->assertCreated()
            ->assertJsonPath('data.billing.subscription.plan.code', 'starter')
            ->assertJsonPath('data.billing.subscription.end_on', '2026-11-16');
    }

    public function test_staff_create_a_plan_with_modules_from_the_catalog_only(): void
    {
        $this->actingAsStaff($this->platformStaff());

        $this->postJson('/api/v1/platform/billing/plans', [
            'code' => 'pro', 'name' => 'Pro', 'price' => 120, 'currency' => 'USD', 'period_months' => 12,
            'max_students' => 1000, 'modules' => ['payroll', 'academics', 'nope'],
        ])->assertUnprocessable()->assertJsonValidationErrors('modules.2');

        $this->postJson('/api/v1/platform/billing/plans', [
            'code' => 'pro', 'name' => 'Pro', 'price' => 120, 'currency' => 'USD', 'period_months' => 12,
            'max_students' => 1000, 'modules' => ['payroll', 'academics'],
        ])->assertCreated()
            ->assertJsonPath('data.modules', ['academics', 'payroll']) // catalog order
            ->assertJsonPath('data.status', 'active');

        $this->postJson('/api/v1/platform/billing/plans', [
            'code' => 'pro', 'name' => 'Pro again', 'price' => 1, 'currency' => 'KHR', 'modules' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->getJson('/api/v1/platform/billing/plans')->assertOk()
            ->assertJsonCount(count(Modules::codes()), 'data.modules')
            ->assertJsonFragment(['code' => 'pro']);
    }

    public function test_a_plan_with_history_is_deactivated_not_deleted_and_legacy_stays(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $used = $this->plan();
        $this->onPlan($this->school(), $used);
        $unused = $this->plan();

        $this->deleteJson("/api/v1/platform/billing/plans/{$used->id}")->assertOk()->assertJsonPath('data.deleted', false);
        $this->assertSame(Plan::STATUS_INACTIVE, $used->fresh()->status);

        $this->deleteJson("/api/v1/platform/billing/plans/{$unused->id}")->assertOk()->assertJsonPath('data.deleted', true);
        $this->assertNull(Plan::query()->find($unused->id));

        $this->deleteJson('/api/v1/platform/billing/plans/'.Plan::legacy()->id)->assertStatus(409);
        $this->patchJson('/api/v1/platform/billing/plans/'.Plan::legacy()->id, ['status' => 'inactive'])->assertStatus(409);
    }

    public function test_subscribing_defaults_the_end_to_one_period_of_the_plan(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $school = $this->school();
        $plan = $this->plan(['period_months' => 3]);

        $this->postJson("/api/v1/platform/schools/{$school->id}/billing/subscribe", ['plan_id' => $plan->id, 'status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.flag', 'none')
            ->assertJsonPath('data.subscription.start_on', '2026-10-18')
            ->assertJsonPath('data.subscription.end_on', '2027-01-17');

        // Already on a plan: change it instead.
        $this->postJson("/api/v1/platform/schools/{$school->id}/billing/subscribe", ['plan_id' => $plan->id, 'status' => 'active'])
            ->assertUnprocessable()->assertJsonValidationErrors('plan_id');
    }

    public function test_an_inactive_plan_cannot_be_sold(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $plan = $this->plan(['status' => Plan::STATUS_INACTIVE]);

        $this->postJson("/api/v1/platform/schools/{$this->school()->id}/billing/subscribe", ['plan_id' => $plan->id, 'status' => 'active'])
            ->assertUnprocessable()->assertJsonValidationErrors('plan_id');
    }

    public function test_changing_plan_keeps_the_end_date_and_replaces_the_old_row(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $school = $this->school();
        $old = $this->onPlan($school, $this->plan(), 'active', '2027-03-31');
        $pro = $this->plan(['code' => 'pro', 'period_months' => 12]);

        $this->postJson("/api/v1/platform/schools/{$school->id}/billing/change-plan", ['plan_id' => $pro->id])
            ->assertOk()
            ->assertJsonPath('data.subscription.plan.code', 'pro')
            ->assertJsonPath('data.subscription.status', 'active')
            ->assertJsonPath('data.subscription.end_on', '2027-03-31')
            ->assertJsonCount(2, 'data.history');

        $this->assertSame(SchoolSubscription::REPLACED, $old->fresh()->status);
        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'billing.plan_changed', 'tenant_id' => $school->id]);

        // To a plan with no period: no end.
        $this->postJson("/api/v1/platform/schools/{$school->id}/billing/change-plan", ['plan_id' => Plan::legacy()->id])
            ->assertOk()->assertJsonPath('data.subscription.end_on', null);
    }

    public function test_only_one_live_subscription_per_school_is_possible(): void
    {
        $school = $this->school();
        $this->onPlan($school, $this->plan());

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->onPlan($school, $this->plan(), 'trial');
    }

    public function test_staff_move_the_end_date_but_never_before_the_start(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $school = $this->school();
        $this->onPlan($school, $this->plan());

        $this->patchJson("/api/v1/platform/schools/{$school->id}/billing", ['end_on' => '2026-10-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('end_on');

        $this->patchJson("/api/v1/platform/schools/{$school->id}/billing", ['end_on' => '2026-12-31', 'notes' => 'Paid in cash'])
            ->assertOk()
            ->assertJsonPath('data.subscription.end_on', '2026-12-31')
            ->assertJsonPath('data.subscription.notes', 'Paid in cash');
    }

    public function test_cancelling_ends_the_live_subscription_with_a_reason(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $school = $this->school();
        $this->onPlan($school, $this->plan());

        $this->postJson("/api/v1/platform/schools/{$school->id}/billing/cancel", [])->assertUnprocessable();

        $this->postJson("/api/v1/platform/schools/{$school->id}/billing/cancel", ['reason' => 'School closed'])
            ->assertOk()
            ->assertJsonPath('data.flag', 'cancelled')
            ->assertJsonPath('data.subscription.status', 'cancelled');

        $this->postJson("/api/v1/platform/schools/{$school->id}/billing/cancel", ['reason' => 'again'])->assertNotFound();
    }

    public function test_the_schools_list_flags_expiring_and_expired_schools(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $plan = $this->plan();
        $expiring = $this->school();
        $this->onPlan($expiring, $plan, 'active', '2026-10-25'); // 7 days, within 14
        $expired = $this->school();
        $this->onPlan($expired, $plan, 'active', '2026-10-10');
        $fine = $this->school();
        $this->onPlan($fine, $plan, 'active', '2027-10-10');
        $none = $this->school();

        $rows = collect($this->getJson('/api/v1/platform/schools?per_page=50')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame('expiring', $rows[$expiring->id]['billing']['flag']);
        $this->assertSame(7, $rows[$expiring->id]['billing']['subscription']['days_left']);
        $this->assertSame('expired', $rows[$expired->id]['billing']['flag']);
        $this->assertSame('none', $rows[$fine->id]['billing']['flag']);
        $this->assertSame('no_plan', $rows[$none->id]['billing']['flag']);
    }

    public function test_billing_settings_are_read_and_changed_with_an_audit_row(): void
    {
        $this->actingAsStaff($this->platformStaff());

        $this->getJson('/api/v1/platform/billing/settings')->assertOk()
            ->assertJsonPath('data.trial_days', 15)
            ->assertJsonPath('data.grace_days', 7)
            ->assertJsonPath('data.warning_days', 14);

        $this->putJson('/api/v1/platform/billing/settings', ['trial_days' => 0])->assertUnprocessable();

        $this->putJson('/api/v1/platform/billing/settings', [
            'trial_days' => 20, 'company_name' => 'New Tech Co., Ltd.', 'company_tax_number' => 'K001-123',
        ])->assertOk()->assertJsonPath('data.trial_days', 20)->assertJsonPath('data.company_name', 'New Tech Co., Ltd.');

        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'billing.settings_updated']);
    }

    public function test_read_only_staff_see_billing_but_cannot_change_it(): void
    {
        $school = $this->school();
        $this->actingAsStaff($this->platformStaff(['platform.billing.read']));

        $this->getJson('/api/v1/platform/billing/plans')->assertOk();
        $this->getJson("/api/v1/platform/schools/{$school->id}/billing")->assertOk();
        $this->postJson('/api/v1/platform/billing/plans', ['code' => 'x'])->assertNotFound();
        $this->postJson("/api/v1/platform/schools/{$school->id}/billing/subscribe", ['plan_id' => Plan::legacy()->id, 'status' => 'active'])->assertNotFound();
        $this->putJson('/api/v1/platform/billing/settings', ['trial_days' => 3])->assertNotFound();

        $this->assertSame(0, DB::table('bo_school_subscriptions')->where('tenant_id', $school->id)->count());
    }
}
