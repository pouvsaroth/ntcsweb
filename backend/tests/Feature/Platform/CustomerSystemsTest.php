<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Tenant;
use App\Platform\Billing\SchoolModules;
use App\Platform\Models\Plan;
use App\Platform\Models\Product;
use App\Platform\Models\SchoolSubscription;
use App\Platform\Services\SchoolDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\FakeSchoolDatabase;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

/**
 * Stage 9b (docs/backoffice/stage-9-products.md): one customer, several systems.
 */
class CustomerSystemsTest extends TestCase
{
    use HasPlatformStaff, RefreshDatabase;

    private Tenant $customer;

    private Plan $schoolPlan;

    private Plan $erpPlan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(SchoolDatabase::class, new FakeSchoolDatabase);
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-20 05:00:00', 'UTC'));

        $this->customer = Tenant::factory()->create();
        $this->schoolPlan = Plan::query()->create([
            'code' => 'school-basic', 'name' => 'School Basic', 'price' => 15, 'currency' => 'USD', 'period_months' => 1,
            'modules' => ['academics'], 'menus' => ['students', 'classes'], 'status' => 'active',
        ]);
        $this->erpPlan = Plan::query()->create([
            'code' => 'erp-basic', 'name' => 'ERP Basic', 'price' => 20, 'currency' => 'USD', 'period_months' => 1,
            'modules' => ['hrm', 'payroll'], 'status' => 'active', 'product_id' => Product::query()->where('code', 'erp')->value('id'),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function subscribe(Plan $plan, string $status = 'active'): void
    {
        $this->postJson("/api/v1/platform/schools/{$this->customer->id}/billing/subscribe", ['plan_id' => $plan->id, 'status' => $status])->assertOk();
    }

    public function test_a_customer_holds_one_live_subscription_per_system(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $this->subscribe($this->schoolPlan);
        $this->subscribe($this->erpPlan);

        // A second plan of the same system is refused; changing within it works.
        $this->postJson("/api/v1/platform/schools/{$this->customer->id}/billing/subscribe", ['plan_id' => $this->schoolPlan->id, 'status' => 'active'])
            ->assertUnprocessable()->assertJsonValidationErrors('plan_id');

        $erpId = $this->erpPlan->product_id;
        $billing = $this->getJson("/api/v1/platform/schools/{$this->customer->id}/billing?product_id={$erpId}")->assertOk();
        $billing->assertJsonPath('data.subscription.plan.code', 'erp-basic')
            ->assertJsonPath('data.product_id', $erpId)
            ->assertJsonCount(2, 'data.systems')
            ->assertJsonPath('data.systems.0.product.code', 'school')
            ->assertJsonPath('data.systems.1.product.code', 'erp');

        // The list shows both systems.
        $row = collect($this->getJson('/api/v1/platform/schools')->json('data'))->firstWhere('id', $this->customer->id);
        $this->assertSame(['school', 'erp'], collect($row['billing']['systems'])->pluck('code')->all());
    }

    public function test_cancelling_one_system_leaves_the_other(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $this->subscribe($this->schoolPlan);
        $this->subscribe($this->erpPlan);

        $this->postJson("/api/v1/platform/schools/{$this->customer->id}/billing/cancel", ['reason' => 'Stopped ERP', 'product_id' => $this->erpPlan->product_id])->assertOk();

        $this->assertSame(['active', 'cancelled'], SchoolSubscription::query()->where('tenant_id', $this->customer->id)->orderBy('id')->pluck('status')->all());
    }

    public function test_the_customer_may_use_every_system_together(): void
    {
        SchoolSubscription::query()->create(['tenant_id' => $this->customer->id, 'plan_id' => $this->schoolPlan->id, 'status' => 'active', 'start_on' => '2026-10-01', 'end_on' => '2026-12-01']);
        SchoolSubscription::query()->create(['tenant_id' => $this->customer->id, 'plan_id' => $this->erpPlan->id, 'status' => 'active', 'start_on' => '2026-10-01', 'end_on' => '2026-12-01']);

        $modules = app(SchoolModules::class);
        $this->assertEqualsCanonicalizing(['academics', 'hrm', 'payroll'], $modules->enabled($this->customer->id));
        $allowed = $modules->filter($this->customer->id, ['students.view', 'classes.view', 'exam-scores.view', 'payroll.view', 'staff.view', 'books.view', 'users.view']);
        // School plan keeps 2 Academics menus; ERP keeps all of HRM and Payroll; Core always.
        $this->assertSame(['students.view', 'classes.view', 'payroll.view', 'staff.view', 'users.view'], $allowed);
    }

    public function test_an_expired_system_goes_after_grace_but_the_customer_is_not_suspended_while_another_is_live(): void
    {
        SchoolSubscription::query()->create(['tenant_id' => $this->customer->id, 'plan_id' => $this->schoolPlan->id, 'status' => 'active', 'start_on' => '2026-10-01', 'end_on' => '2026-12-01']);
        SchoolSubscription::query()->create(['tenant_id' => $this->customer->id, 'plan_id' => $this->erpPlan->id, 'status' => 'active', 'start_on' => '2026-09-01', 'end_on' => '2026-09-30']);

        $this->artisan('platform:billing-tick')->assertSuccessful();
        $this->assertSame('expired', SchoolSubscription::query()->where('plan_id', $this->erpPlan->id)->value('status'));
        $this->assertSame(Tenant::STATUS_ACTIVE, $this->customer->fresh()->status);

        // ERP ended 30-09; grace (7 days) is over by 20-10 → its modules are gone.
        app(SchoolModules::class)->forget();
        $this->assertSame(['academics'], app(SchoolModules::class)->enabled($this->customer->id));
    }

    public function test_a_payment_pays_for_the_system_asked_for(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $this->subscribe($this->schoolPlan);
        $this->subscribe($this->erpPlan);

        $this->getJson("/api/v1/platform/schools/{$this->customer->id}/billing/payment-quote?periods=2&product_id={$this->erpPlan->product_id}")
            ->assertOk()->assertJsonPath('data.plan', 'ERP Basic')->assertJsonPath('data.amount', '40.00');
        $this->postJson("/api/v1/platform/schools/{$this->customer->id}/billing/payments", ['periods' => 1, 'method' => 'cash', 'product_id' => $this->erpPlan->product_id])->assertCreated();

        $erpSub = SchoolSubscription::query()->where('plan_id', $this->erpPlan->id)->firstOrFail();
        $this->assertDatabaseHas('bo_payments', ['subscription_id' => $erpSub->id]);
    }
}
