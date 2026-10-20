<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Tenant;
use App\Platform\Models\Plan;
use App\Platform\Models\Product;
use App\Platform\Models\SchoolSubscription;
use App\Platform\Models\Signup;
use App\Platform\Services\SchoolDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\FakeSchoolDatabase;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

/**
 * Stage 9c (docs/backoffice/stage-9-products.md): the website and sign-up per system.
 */
class SystemSignupTest extends TestCase
{
    use HasPlatformStaff, RefreshDatabase;

    private Product $erp;

    private Plan $erpPlan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(SchoolDatabase::class, new FakeSchoolDatabase);
        Notification::fake();
        RateLimiter::clear('signups:127.0.0.1');
        config(['platform.website_school' => 'newtech']);
        Tenant::factory()->create(['slug' => 'newtech']);

        $this->erp = Product::query()->where('code', 'erp')->firstOrFail();
        $this->erpPlan = Plan::query()->create([
            'code' => 'erp-basic', 'name' => 'ERP Basic', 'price' => 20, 'currency' => 'USD', 'period_months' => 1,
            'modules' => ['hrm', 'payroll'], 'status' => 'active', 'show_on_website' => true, 'product_id' => $this->erp->id,
        ]);
        Plan::query()->create([
            'code' => 'school-basic', 'name' => 'School Basic', 'price' => 15, 'currency' => 'USD', 'period_months' => 1,
            'modules' => ['academics'], 'status' => 'active', 'show_on_website' => true,
        ]);
    }

    private function form(array $overrides = []): array
    {
        return $overrides + [
            'school_name' => 'ក្រុមហ៊ុនអង្គរ', 'slug' => 'angkor-co', 'owner_name' => 'Sok Dara', 'owner_email' => 'dara@example.com',
            'owner_phone' => '012345678', 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1',
        ];
    }

    public function test_each_system_has_its_own_plans_page(): void
    {
        $this->withHeader('X-Tenant', 'newtech')->getJson('/api/v1/public/plans?system=erp')->assertOk()
            ->assertJsonPath('data.system.code', 'erp')
            ->assertJsonPath('data.system.is_school', false)
            ->assertJsonPath('data.plans.*.code', ['erp-basic'])
            ->assertJsonPath('data.trial_days', 0); // no trial plan set for ERP

        $this->withHeader('X-Tenant', 'newtech')->getJson('/api/v1/public/plans')->assertOk()
            ->assertJsonPath('data.system.code', 'school')
            ->assertJsonPath('data.plans.*.code', ['school-basic']);

        $this->withHeader('X-Tenant', 'newtech')->getJson('/api/v1/public/plans?system=nope')->assertNotFound();
    }

    public function test_a_system_without_a_trial_plan_has_no_free_trial(): void
    {
        $this->withHeader('X-Tenant', 'newtech')->postJson('/api/v1/public/signup', $this->form(['plan' => 'trial', 'system' => 'erp']))
            ->assertUnprocessable()->assertJsonValidationErrors('plan');
    }

    public function test_a_free_trial_of_another_system_runs_on_its_trial_plan(): void
    {
        $this->erp->update(['trial_plan_id' => $this->erpPlan->id]);

        $this->withHeader('X-Tenant', 'newtech')->postJson('/api/v1/public/signup', $this->form(['plan' => 'trial', 'system' => 'erp']))
            ->assertCreated()->assertJsonPath('data.status', 'created')->assertJsonPath('data.system.code', 'erp');

        $tenant = Tenant::query()->where('slug', 'angkor-co')->firstOrFail();
        $sub = SchoolSubscription::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame(SchoolSubscription::TRIAL, $sub->status);
        $this->assertSame($this->erp->id, $sub->product_id);
        $this->assertSame('erp-basic', $sub->plan->code);
    }

    public function test_a_plan_of_another_system_cannot_be_bought_through_this_one(): void
    {
        $this->withHeader('X-Tenant', 'newtech')->postJson('/api/v1/public/signup', $this->form(['plan' => 'school-basic', 'system' => 'erp']))
            ->assertUnprocessable()->assertJsonValidationErrors('plan');

        $this->withHeader('X-Tenant', 'newtech')->postJson('/api/v1/public/signup', $this->form(['plan' => 'erp-basic', 'system' => 'erp']))
            ->assertCreated()->assertJsonPath('data.status', 'pending_payment')->assertJsonPath('data.amount', '20.00');
        $this->assertSame($this->erp->id, Signup::query()->value('product_id'));
    }

    public function test_staff_set_a_system_trial_plan_only_from_its_own_plans(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $schoolPlan = Plan::query()->where('code', 'school-basic')->value('id');

        $this->patchJson("/api/v1/platform/billing/products/{$this->erp->id}", ['trial_plan_id' => $schoolPlan])
            ->assertUnprocessable()->assertJsonValidationErrors('trial_plan_id');
        $this->patchJson("/api/v1/platform/billing/products/{$this->erp->id}", ['trial_plan_id' => $this->erpPlan->id])
            ->assertOk()->assertJsonPath('data.trial_plan_id', $this->erpPlan->id);
    }
}
