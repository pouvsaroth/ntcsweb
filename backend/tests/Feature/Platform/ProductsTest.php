<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Platform\Models\Plan;
use App\Platform\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

/**
 * Stage 9a (docs/backoffice/stage-9-products.md): systems, and plans per system.
 */
class ProductsTest extends TestCase
{
    use HasPlatformStaff, RefreshDatabase;

    public function test_the_school_and_erp_systems_exist_and_every_plan_sells_one(): void
    {
        $this->assertSame(['school', 'erp'], Product::query()->orderBy('sort_order')->pluck('code')->all());
        $this->assertSame(Product::school()->id, Plan::legacy()->product_id);

        // A plan made without a system sells the school system.
        $plan = Plan::query()->create(['code' => 'x', 'name' => 'X', 'price' => 1, 'currency' => 'USD', 'modules' => ['academics'], 'status' => 'active']);
        $this->assertSame(Product::SCHOOL, $plan->product->code);
    }

    public function test_staff_add_a_system_and_a_plan_only_uses_its_modules(): void
    {
        $this->actingAsStaff($this->platformStaff());

        $gps = $this->postJson('/api/v1/platform/billing/products', ['code' => 'gps', 'name' => 'SmartGPS', 'name_km' => 'ជីភីអេស', 'modules' => ['projects', 'assets']])
            ->assertCreated()
            ->assertJsonPath('data.modules', ['assets', 'projects'])
            ->json('data.id');

        $body = ['code' => 'gps-basic', 'name' => 'GPS Basic', 'price' => 9, 'currency' => 'USD', 'period_months' => 1, 'product_id' => $gps];
        $this->postJson('/api/v1/platform/billing/plans', $body + ['modules' => ['academics']])
            ->assertUnprocessable()->assertJsonValidationErrors('modules');
        $planId = $this->postJson('/api/v1/platform/billing/plans', $body + ['modules' => ['assets']])
            ->assertCreated()->assertJsonPath('data.product.code', 'gps')->json('data.id');

        $list = $this->getJson('/api/v1/platform/billing/plans')->assertOk();
        $this->assertContains('gps', collect($list->json('data.products'))->pluck('code')->all());

        // A module a plan still uses cannot be taken out of the system.
        $this->patchJson("/api/v1/platform/billing/products/{$gps}", ['modules' => ['projects']])
            ->assertUnprocessable()->assertJsonValidationErrors('modules');
        $this->patchJson("/api/v1/platform/billing/products/{$gps}", ['modules' => ['assets', 'payroll']])->assertOk();

        // With plans it is deactivated, not deleted.
        $this->deleteJson("/api/v1/platform/billing/products/{$gps}")->assertOk()->assertJsonPath('data.deleted', false);
        $this->assertSame('inactive', Product::query()->find($gps)->status);
        $this->assertTrue(Plan::query()->whereKey($planId)->exists());
    }

    public function test_the_school_system_cannot_be_removed_or_renamed_in_code(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $school = Product::school();

        $this->deleteJson("/api/v1/platform/billing/products/{$school->id}")->assertStatus(409);
        $this->patchJson("/api/v1/platform/billing/products/{$school->id}", ['code' => 'other'])->assertStatus(409);
        $this->patchJson("/api/v1/platform/billing/products/{$school->id}", ['name' => 'NewTech School System'])->assertOk();
    }

    public function test_a_system_logo_and_read_only_staff(): void
    {
        Storage::fake('public');
        $this->actingAsStaff($this->platformStaff());
        $erp = Product::query()->where('code', 'erp')->firstOrFail();

        $url = $this->post("/api/v1/platform/billing/products/{$erp->id}/logo", ['logo' => UploadedFile::fake()->image('erp.png')], ['Accept' => 'application/json'])
            ->assertOk()->json('data.logo_url');
        $this->assertNotNull($url);

        $this->actingAsStaff($this->platformStaff(['platform.billing.read']));
        $this->getJson('/api/v1/platform/billing/products')->assertOk()->assertJsonCount(2, 'data.products');
        $this->postJson('/api/v1/platform/billing/products', ['code' => 'z', 'name' => 'Z', 'modules' => ['hrm']])->assertNotFound();
    }
}
