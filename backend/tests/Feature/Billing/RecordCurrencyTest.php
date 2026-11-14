<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Mail\InvoiceMail;
use App\Models\Asset;
use App\Models\AssetRepair;
use App\Models\AuditLog;
use App\Models\CurrencyRate;
use App\Models\Enrollment;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Tenant;
use App\Support\Assets\AssetStatus;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
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

    public function test_outstanding_balances_convert_dollar_and_riel_invoices_before_adding(): void
    {
        $this->khrSchool([Permissions::ACCOUNTING_DASHBOARD_VIEW]);
        Invoice::factory()->create(['currency' => 'USD', 'total' => 10, 'balance' => 10]);
        Invoice::factory()->create(['currency' => 'KHR', 'total' => 30000, 'balance' => 30000]);

        // $10 × 4,000 + 30,000 ៛ = 70,000 ៛ — not 30,010.
        $this->getJson('/api/v1/accounting/dashboard')
            ->assertOk()
            ->assertJsonPath('data.currency', 'KHR')
            ->assertJsonPath('data.outstanding_receivables', 70000);
    }

    public function test_the_invoice_email_shows_riel_as_riel(): void
    {
        $this->khrSchool([]);
        $invoice = Invoice::factory()->create(['currency' => 'KHR', 'total' => 30000, 'balance' => 30000]);

        $html = (new InvoiceMail($invoice))->render();

        $this->assertStringContainsString('30,000 ៛', $html);
        $this->assertStringNotContainsString('$30,000', $html);
        // ...and in dollars too, at 4,000 ៛ a dollar.
        $this->assertStringContainsString('$7.50', $html);
    }

    private function documentView(Invoice $invoice, ?Payment $payment = null): string
    {
        $invoice->load(['student', 'items', 'invoiceStudents', 'payments']);

        return View::make($payment ? 'pdf.receipt' : 'pdf.invoice', [
            'payment' => $payment,
            'invoice' => $invoice,
            'tenant' => $this->tenant,
            'issuerStaff' => null,
            'logoDataUri' => null,
            'stampDataUri' => null,
            'signatureDataUri' => null,
            'khmerFontRegular' => '',
            'khmerFontBold' => '',
        ])->render();
    }

    private function rielInvoice(string $date): Invoice
    {
        $invoice = Invoice::factory()->forStudent(Student::factory()->create())->create([
            'currency' => 'KHR', 'invoice_date' => $date, 'subtotal' => 41000, 'total' => 41000, 'paid_amount' => 0, 'balance' => 41000,
        ]);
        InvoiceItem::factory()->forInvoice($invoice)->create(['unit_price' => 41000, 'subtotal' => 41000, 'total' => 41000, 'quantity' => 1, 'discount' => 0]);

        return $invoice;
    }

    public function test_an_invoice_shows_dollars_and_riel_at_the_rate_on_its_date(): void
    {
        $this->khrSchool([]);
        CurrencyRate::query()->create(['effective_date' => '2026-06-01', 'khr_per_usd' => 4100]);

        // Before June the 4,000 rate is in effect: 41,000 ៛ = $10.25.
        $html = $this->documentView($this->rielInvoice('2026-05-20'));
        $this->assertStringContainsString('41,000 ៛', $html);
        $this->assertStringContainsString('$10.25', $html);
        $this->assertStringContainsString('1 USD = 4,000 ៛', $html);

        // From June it is 4,100: 41,000 ៛ = $10.00.
        $html = $this->documentView($this->rielInvoice('2026-06-15'));
        $this->assertStringContainsString('$10.00', $html);
        $this->assertStringContainsString('1 USD = 4,100 ៛', $html);
        $this->assertStringContainsString('(from 01-06-2026)', $html);
    }

    public function test_a_receipt_converts_at_the_rate_on_the_payment_date(): void
    {
        $this->khrSchool([]);
        CurrencyRate::query()->create(['effective_date' => '2026-06-01', 'khr_per_usd' => 4100]);
        $invoice = $this->rielInvoice('2026-05-20');
        $payment = Payment::factory()->forInvoice($invoice)->create(['amount' => 41000, 'payment_date' => '2026-06-10']);

        $html = $this->documentView($invoice, $payment);

        $this->assertStringContainsString('1 USD = 4,100 ៛', $html);
        $this->assertStringContainsString('$10.00', $html);
    }

    public function test_with_no_rate_entered_the_other_currency_shows_a_dash_not_a_guess(): void
    {
        $this->khrSchool([]);
        CurrencyRate::query()->delete();

        $html = $this->documentView($this->rielInvoice('2026-05-20'));

        $this->assertStringContainsString('41,000 ៛', $html);
        $this->assertStringContainsString('No exchange rate entered yet', $html);
        $this->assertStringNotContainsString('$41,000', $html);
    }

    public function test_class_revenue_converts_dollar_and_riel_invoices_before_adding(): void
    {
        $this->khrSchool([Permissions::ACADEMIC_REPORTS_VIEW]);
        $class = SchoolClass::factory()->create();
        foreach ([['USD', 10], ['KHR', 30000]] as [$currency, $amount]) {
            $enrollment = Enrollment::factory()->forClass($class)->create();
            $invoice = Invoice::factory()->create(['currency' => $currency, 'total' => $amount, 'balance' => $amount]);
            InvoiceItem::factory()->forInvoice($invoice)->create([
                'unit_price' => $amount, 'subtotal' => $amount, 'total' => $amount, 'quantity' => 1,
                'reference_type' => Enrollment::class, 'reference_id' => $enrollment->id,
            ]);
        }

        $row = collect($this->getJson('/api/v1/academic-reports/classes')->assertOk()->json('data'))->firstWhere('class_id', $class->id);
        $this->assertEqualsWithDelta(70000, $row['revenue'], 0.001);
    }
}
