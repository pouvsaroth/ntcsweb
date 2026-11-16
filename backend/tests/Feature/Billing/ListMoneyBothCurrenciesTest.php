<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\CoursePackage;
use App\Models\CurrencyRate;
use App\Models\Expense;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\MarketingCampaign;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Authorization\Permissions;
use App\Support\Billing\InvoiceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasChartOfAccounts;
use Tests\TestCase;

/**
 * Lists on screen show every amount in USD and KHR, each converted at the
 * rate on its own record's date.
 */
class ListMoneyBothCurrenciesTest extends TestCase
{
    use HasAcademicAdmin, HasChartOfAccounts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_VIEW, Permissions::PAYMENTS_VIEW, Permissions::TRANSACTIONS_VIEW, Permissions::EXPENSE_VIEW, Permissions::PRODUCTS_VIEW, Permissions::COURSE_PACKAGES_VIEW, Permissions::CAMPAIGNS_VIEW]);
        CurrencyRate::factory()->create(['effective_date' => '2026-01-01', 'khr_per_usd' => 4000]);
        CurrencyRate::factory()->create(['effective_date' => '2026-06-01', 'khr_per_usd' => 4100]);
    }

    public function test_invoices_and_payments_carry_both_currencies_at_their_own_dates_rate(): void
    {
        $invoice = Invoice::factory()->status(InvoiceStatus::PARTIALLY_PAID)->create([
            'invoice_date' => '2026-02-10', 'currency' => 'USD', 'total' => 100, 'paid_amount' => 40, 'balance' => 60,
        ]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'student_id' => $invoice->student_id, 'amount' => 82000, 'currency' => 'KHR', 'payment_date' => '2026-07-01']);

        $row = collect($this->getJson('/api/v1/invoices')->assertOk()->json('data'))->firstWhere('id', $invoice->id);
        $this->assertEquals([100, 400000], [$row['total_usd'], $row['total_khr']]);
        $this->assertEquals([40, 160000], [$row['paid_amount_usd'], $row['paid_amount_khr']]);
        $this->assertEquals([60, 240000], [$row['balance_usd'], $row['balance_khr']]);

        // 82,000 riel on 1 July, at 4100.
        $payment = $this->getJson('/api/v1/payments')->assertOk()->json('data.0');
        $this->assertEquals([20, 82000], [$payment['amount_usd'], $payment['amount_khr']]);
    }

    public function test_ledger_lines_carry_both_currencies(): void
    {
        FinancialTransaction::factory()->create(['amount' => 40000, 'currency' => Tenant::CURRENCY_KHR, 'transaction_date' => '2026-03-01']);

        $line = $this->getJson('/api/v1/financial-transactions')->assertOk()->json('data.0');
        $this->assertEquals([10, 40000], [$line['amount_usd'], $line['amount_khr']]);
    }

    public function test_prices_use_todays_rate_and_a_campaign_its_start_dates(): void
    {
        Product::factory()->create(['price' => 10, 'currency' => 'USD']);
        CoursePackage::factory()->create(['fee_monthly' => 30, 'currency' => 'USD']);
        MarketingCampaign::factory()->create(['start_on' => '2026-03-01', 'budget' => 200, 'currency' => 'USD']);

        // Today: the 4100 rate.
        $product = $this->getJson('/api/v1/products')->assertOk()->json('data.0');
        $this->assertEquals([10, 41000], [$product['price_usd'], $product['price_khr']]);
        $package = $this->getJson('/api/v1/course-packages')->assertOk()->json('data.0');
        $this->assertEquals([30, 123000], [$package['fee_monthly_usd'], $package['fee_monthly_khr']]);
        // 1 March: the 4000 rate.
        $campaign = $this->getJson('/api/v1/marketing-campaigns')->assertOk()->json('data.0');
        $this->assertEquals([200, 800000], [$campaign['budget_usd'], $campaign['budget_khr']]);
    }

    public function test_expenses_carry_both_currencies_at_the_expense_dates_rate(): void
    {
        $this->setUpChartOfAccounts();
        Expense::factory()->create([
            'account_id' => $this->electricityAccount->id, 'expense_entity_id' => $this->expenseEntity->id,
            'amount' => 50, 'currency' => 'USD', 'expense_date' => '2026-07-05', 'created_by' => null,
        ]);

        // 5 July, at 4100.
        $row = $this->getJson('/api/v1/expenses')->assertOk()->json('data.0');
        $this->assertEquals([50, 205000], [$row['amount_usd'], $row['amount_khr']]);
    }
}
