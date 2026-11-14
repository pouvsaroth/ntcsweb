<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Asset;
use App\Models\AssetRepair;
use App\Models\AuditLog;
use App\Models\CurrencyRate;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Student;
use App\Models\Tenant;
use App\Support\Assets\AssetStatus;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAssetCatalog;
use Tests\Concerns\HasChartOfAccounts;
use Tests\TestCase;

/**
 * Money in its own currency: products, assets, repairs and the like carry
 * USD or KHR, are never assumed to be dollars, and are converted (not just
 * added) when they meet another currency.
 */
class RecordCurrencyTest extends TestCase
{
    use HasAcademicAdmin, HasAssetCatalog, HasChartOfAccounts, RefreshDatabase;

    private function khrSchool(array $permissions): void
    {
        $this->tenant = Tenant::factory()->create(['default_currency' => Tenant::CURRENCY_KHR]);
        $this->actingAsAdminWithPermissions($permissions);
        CurrencyRate::query()->create(['effective_date' => '2020-01-01', 'khr_per_usd' => 4000]);
    }

    public function test_a_new_product_starts_in_the_school_currency_and_can_be_priced_in_riel(): void
    {
        $this->khrSchool([Permissions::PRODUCTS_CREATE, Permissions::PRODUCTS_VIEW]);

        $this->postJson('/api/v1/products', ['code' => 'BOOK1', 'name' => 'Book', 'price' => 30000])
            ->assertCreated()
            ->assertJsonPath('data.currency', 'KHR')
            ->assertJsonPath('data.price', 30000);

        $this->postJson('/api/v1/products', ['code' => 'BOOK2', 'name' => 'Imported book', 'price' => 12, 'currency' => 'USD'])
            ->assertCreated()
            ->assertJsonPath('data.currency', 'USD');
    }

    public function test_a_dollar_product_on_a_riel_invoice_is_converted_not_copied(): void
    {
        $this->khrSchool([Permissions::INVOICES_CREATE, Permissions::INVOICES_VIEW]);
        $dollarBook = Product::factory()->create(['price' => 10, 'currency' => 'USD']);
        $rielPen = Product::factory()->create(['price' => 2000, 'currency' => 'KHR']);

        $response = $this->postJson('/api/v1/invoices', [
            'student_id' => Student::factory()->create()->id,
            'items' => [['product_id' => $dollarBook->id, 'quantity' => 1], ['product_id' => $rielPen->id, 'quantity' => 2]],
        ])->assertCreated();

        // $10 at 4,000 = 40,000 ៛, plus 2 × 2,000 ៛.
        $response->assertJsonPath('data.currency', 'KHR');
        $response->assertJsonPath('data.items.0.unit_price', 40000);
        $response->assertJsonPath('data.total', 44000);
    }

    public function test_a_riel_repair_makes_a_riel_expense_and_its_audit_text_says_riel(): void
    {
        $this->khrSchool([Permissions::ASSETS_CREATE, Permissions::ASSET_REPAIRS_CREATE, Permissions::ASSET_REPAIRS_COMPLETE]);
        $this->setUpAssetCatalog();
        $this->setUpChartOfAccounts();
        $asset = $this->createAsset(['status' => AssetStatus::BROKEN]);
        $repairId = $this->postJson("/api/v1/assets/{$asset->id}/repairs", ['repair_shop_id' => $this->repairShop->id])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/asset-repairs/{$repairId}/complete", [
            'expense_account_id' => $this->electricityAccount->id,
            'currency' => 'KHR',
            'parts_cost' => 30000,
        ])->assertOk()->assertJsonPath('data.currency', 'KHR');

        $expense = Expense::query()->where('reference_type', AssetRepair::class)->where('reference_id', $repairId)->sole();
        $this->assertSame('KHR', $expense->currency);
        $this->assertSame('30000.00', (string) $expense->amount);
        $this->assertStringContainsString('30,000 ៛', (string) AuditLog::query()->where('auditable_type', AssetRepair::class)->latest('id')->value('description'));
    }

    public function test_the_asset_dashboard_converts_dollar_and_riel_prices_before_adding_them(): void
    {
        $this->khrSchool([Permissions::ASSET_REPORTS_VIEW]);
        $this->setUpAssetCatalog();
        Asset::query()->whereKey($this->createAsset(['purchase_price' => 335, 'purchase_date' => '2026-01-10'])->id)->update(['currency' => 'USD']);
        Asset::query()->whereKey($this->createAsset(['purchase_price' => 60000, 'purchase_date' => '2026-01-10'])->id)->update(['currency' => 'KHR']);

        // $335 × 4,000 + 60,000 ៛ = 1,400,000 ៛ — not 60,335.
        $this->getJson('/api/v1/assets/dashboard')
            ->assertOk()
            ->assertJsonPath('data.currency', 'KHR')
            ->assertJsonPath('data.total_investment', 1400000);
    }
}
