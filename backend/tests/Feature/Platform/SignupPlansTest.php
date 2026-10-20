<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Tenant;
use App\Platform\Models\BillingSettings;
use App\Platform\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

/**
 * Stage 8a (docs/backoffice/stage-8-signup.md): plans on the website.
 */
class SignupPlansTest extends TestCase
{
    use HasPlatformStaff, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.website_school' => 'newtech']);
        Tenant::factory()->create(['slug' => 'newtech']);
        Tenant::factory()->create(['slug' => 'other']);
    }

    private function plan(array $overrides = []): Plan
    {
        return Plan::query()->create($overrides + [
            'code' => 'p-'.fake()->unique()->numberBetween(1, 99999),
            'name' => 'Starter',
            'price' => 15,
            'currency' => 'USD',
            'period_months' => 1,
            'modules' => ['academics'],
            'status' => Plan::STATUS_ACTIVE,
            'show_on_website' => true,
        ]);
    }

    public function test_staff_set_the_website_fields_and_only_one_plan_is_recommended(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $first = $this->plan(['recommended' => true]);

        $id = $this->postJson('/api/v1/platform/billing/plans', [
            'code' => 'growth', 'name' => 'Growth', 'price' => 29, 'currency' => 'USD', 'period_months' => 1, 'modules' => ['academics'],
            'show_on_website' => true, 'name_km' => 'ណែនាំ', 'tagline_km' => 'សម្រាប់សាលាមធ្យម',
            'features_km' => "- មុខងារ Starter\n- គ្រប់គ្រងថ្លៃសិក្សា", 'recommended' => true, 'website_order' => 2,
        ])->assertCreated()
            ->assertJsonPath('data.show_on_website', true)
            ->assertJsonPath('data.recommended', true)
            ->assertJsonPath('data.website_order', 2)
            ->json('data.id');

        $this->assertFalse($first->fresh()->recommended);
        $this->patchJson("/api/v1/platform/billing/plans/{$first->id}", ['recommended' => true])->assertOk();
        $this->assertFalse(Plan::query()->find($id)->recommended);
    }

    public function test_the_legacy_plan_cannot_be_shown_on_the_website(): void
    {
        $this->actingAsStaff($this->platformStaff());

        $this->patchJson('/api/v1/platform/billing/plans/'.Plan::legacy()->id, ['show_on_website' => true])->assertStatus(409);
    }

    public function test_the_website_lists_only_shown_active_plans_in_order_with_features_as_lines(): void
    {
        BillingSettings::current()->update(['trial_days' => 15]);
        $this->plan(['code' => 'pro', 'name' => 'Professional', 'price' => 59, 'website_order' => 3]);
        $this->plan(['code' => 'starter', 'name' => 'Starter', 'website_order' => 1, 'features_km' => "- ចុះឈ្មោះសិស្ស\r\n\r\n• គ្រប់គ្រងថ្នាក់\n"]);
        $this->plan(['code' => 'hidden', 'show_on_website' => false]);
        $this->plan(['code' => 'old', 'status' => Plan::STATUS_INACTIVE]);
        Plan::legacy()->forceFill(['show_on_website' => true])->save();

        $this->withHeader('X-Tenant', 'newtech')->getJson('/api/v1/public/plans')->assertOk()
            ->assertJsonPath('data.trial_days', 15)
            ->assertJsonPath('data.plans.*.code', ['starter', 'pro'])
            ->assertJsonPath('data.plans.0.features_km', ['ចុះឈ្មោះសិស្ស', 'គ្រប់គ្រងថ្នាក់'])
            ->assertJsonMissingPath('data.plans.0.modules');
    }

    public function test_only_the_website_school_has_a_plans_page(): void
    {
        $this->plan();

        $this->withHeader('X-Tenant', 'other')->getJson('/api/v1/public/plans')->assertNotFound();
    }

    public function test_one_services_card_may_open_the_plans_page(): void
    {
        $this->actingAsStaff($this->platformStaff(['platform.website.read', 'platform.website.manage']));
        $card = ['kind' => 'plans', 'status' => 'active', 'title_en' => 'School Management System', 'title_km' => 'ប្រព័ន្ធគ្រប់គ្រងសាលា'];

        $this->post('/api/v1/platform/website/services/cards', $card, ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.kind', 'plans')
            ->assertJsonPath('data.product_code', 'school')
            ->assertJsonPath('data.slug', '');
        // One plans card per system: a second for the school system is refused; one for ERP is fine.
        $this->post('/api/v1/platform/website/services/cards', $card, ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('product_code');
        $this->post('/api/v1/platform/website/services/cards', $card + ['product_code' => 'erp'], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.product_code', 'erp');
    }
}
