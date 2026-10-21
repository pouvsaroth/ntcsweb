<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Position;
use App\Models\Tenant;
use App\Modules\ModuleRegistry;
use App\Platform\Billing\Modules;
use App\Platform\Models\BillingSettings;
use App\Platform\Models\Plan;
use App\Platform\Models\Product;
use App\Platform\Services\SchoolDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\FakeSchoolDatabase;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

/**
 * The module registry (docs/architecture/multi-product.md): modules declared
 * once, and each module's starting data only for customers that have it.
 */
class ModuleRegistryTest extends TestCase
{
    use HasPlatformStaff, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(SchoolDatabase::class, new FakeSchoolDatabase);
        Notification::fake();
    }

    private function hasTeacherPosition(Tenant $tenant): bool
    {
        $this->actingInTenant($tenant);

        return Position::query()->where('name', 'Teacher')->exists();
    }

    private function createCustomer(string $slug): Tenant
    {
        $this->postJson('/api/v1/platform/schools', ['name' => $slug, 'slug' => $slug, 'owner_name' => 'Owner', 'owner_email' => "{$slug}@example.com"])->assertCreated();

        return Tenant::query()->where('slug', $slug)->firstOrFail();
    }

    public function test_the_registry_is_the_module_list_plans_use(): void
    {
        $this->assertSame(['academics', 'finance', 'accounting', 'hrm', 'payroll', 'assets', 'library', 'website', 'projects'], Modules::codes());
        $this->assertSame('students', Modules::list()[0]['menus'][8]['code']);
        $this->assertSame('academics', Modules::slugModules()['dashboard.cards.enrollment']);
        $this->assertSame(['Payroll', 'បើកប្រាក់បៀវត្សរ៍'], Modules::menuLabels()['payroll']);
        foreach (ModuleRegistry::all() as $code => $module) {
            $this->assertSame($code, $module->code());
            $this->assertNotEmpty($module->menus(), "{$code} has no menus");
        }
    }

    public function test_a_school_customer_still_gets_the_teacher_position(): void
    {
        $this->actingAsStaff($this->platformStaff());

        // Trial on Legacy = every module, as every school until now.
        $this->assertTrue($this->hasTeacherPosition($this->createCustomer('angkor')));
    }

    public function test_a_customer_without_academics_gets_no_school_starting_data_until_it_gets_the_school_system(): void
    {
        $this->actingAsStaff($staff = $this->platformStaff());
        $erp = Plan::query()->create([
            'code' => 'erp-trial', 'name' => 'ERP trial', 'price' => 0, 'currency' => 'USD', 'period_months' => 1,
            'modules' => ['hrm', 'payroll'], 'status' => 'active', 'product_id' => Product::query()->where('code', 'erp')->value('id'),
        ]);
        BillingSettings::current()->update(['trial_plan_id' => $erp->id]);

        $shop = $this->createCustomer('bayon-shop');
        $this->assertFalse($this->hasTeacherPosition($shop));

        // It adds the School system: Academics' starting data follows.
        $school = Plan::query()->create([
            'code' => 'school-basic', 'name' => 'School', 'price' => 15, 'currency' => 'USD', 'period_months' => 1,
            'modules' => ['academics'], 'status' => 'active',
        ]);
        $this->actingAsStaff($staff);
        $this->postJson("/api/v1/platform/schools/{$shop->id}/billing/subscribe", ['plan_id' => $school->id, 'status' => 'active'])->assertOk();
        $this->assertTrue($this->hasTeacherPosition($shop->fresh()));
    }
}
