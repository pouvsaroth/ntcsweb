<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Models\Product;
use App\Models\Student;
use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\Billing\PaymentMethod;
use App\Support\Billing\ProductType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasChartOfAccounts;
use Tests\TestCase;

/** Reproduces the spec's own complete worked example (section 37) end to end. */
class ReportsTest extends TestCase
{
    use HasAcademicAdmin, HasChartOfAccounts, RefreshDatabase;

    public function test_the_complete_scenario_from_the_spec_matches_exactly(): void
    {
        $admin = $this->actingAsAdminWithPermissions([
            Permissions::INVOICES_CREATE, Permissions::PAYMENTS_CREATE,
            Permissions::EXPENSE_CREATE, Permissions::EXPENSE_APPROVE, Permissions::EXPENSE_PAY,
            Permissions::REPORTS_FINANCIAL_VIEW,
        ]);
        $this->setUpChartOfAccounts();

        $course = Product::factory()->forTenant($this->tenant)->create(['type' => ProductType::COURSE_FEE, 'price' => 100]);
        $book = Product::factory()->forTenant($this->tenant)->create(['type' => ProductType::BOOK, 'price' => 15]);
        $tshirt = Product::factory()->forTenant($this->tenant)->create(['type' => ProductType::T_SHIRT, 'price' => 10]);
        $student = Student::factory()->forTenant($this->tenant)->create();

        $invoiceId = $this->postJson('/api/v1/invoices', [
            'student_id' => $student->id,
            'items' => [
                ['product_id' => $course->id, 'quantity' => 1],
                ['product_id' => $book->id, 'quantity' => 1],
                ['product_id' => $tshirt->id, 'quantity' => 1],
            ],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", [
            'amount' => 125,
            'payment_method' => PaymentMethod::CASH,
        ])->assertCreated();

        // Electricity expense, created -> approved (by someone else) -> paid.
        $expenseId = $this->postJson('/api/v1/expenses', [
            'account_id' => $this->electricityAccount->id,
            'amount' => 30,
        ])->assertCreated()->json('data.id');

        $approver = User::factory()->forTenant($this->tenant)->create();
        $approver->attachRoles($admin->roles()->first());
        $this->actingAsTenantUser($approver);
        $approver->setRelation('tenant', $this->tenant);
        $this->postJson("/api/v1/expenses/{$expenseId}/approve", [])->assertOk();

        $this->actingAsTenantUser($admin);
        $admin->setRelation('tenant', $this->tenant);
        $this->postJson("/api/v1/expenses/{$expenseId}/pay", ['cash_account_id' => $this->cashAccount->id])->assertOk();

        // Revenue Report
        $revenue = $this->getJson('/api/v1/accounting/reports/revenue')->assertOk();
        $revenue->assertJsonPath('data.total', 125);

        // Expense Report
        $expenses = $this->getJson('/api/v1/accounting/reports/expenses')->assertOk();
        $expenses->assertJsonPath('data.total', 30);

        // Profit & Loss
        $pl = $this->getJson('/api/v1/accounting/reports/profit-loss')->assertOk();
        $pl->assertJsonPath('data.revenue.total', 125);
        $pl->assertJsonPath('data.expenses.total', 30);
        $pl->assertJsonPath('data.net_profit', 95);
        $pl->assertJsonPath('data.is_profit', true);
    }

    public function test_financial_reports_require_the_reports_financial_view_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/v1/accounting/reports/revenue')->assertForbidden();
        $this->getJson('/api/v1/accounting/reports/profit-loss')->assertForbidden();
        $this->getJson('/api/v1/accounting/reports/cash-flow')->assertForbidden();
    }

    public function test_cash_flow_reports_opening_inflow_outflow_and_closing_balance(): void
    {
        $this->actingAsAdminWithPermissions([
            Permissions::INVOICES_CREATE, Permissions::PAYMENTS_CREATE, Permissions::REPORTS_FINANCIAL_VIEW,
        ]);
        $this->setUpChartOfAccounts();

        $course = Product::factory()->forTenant($this->tenant)->create(['type' => ProductType::COURSE_FEE, 'price' => 100]);
        $student = Student::factory()->forTenant($this->tenant)->create();
        $invoiceId = $this->postJson('/api/v1/invoices', [
            'student_id' => $student->id,
            'items' => [['product_id' => $course->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", ['amount' => 100, 'payment_method' => PaymentMethod::CASH])->assertCreated();

        $today = now()->toDateString();
        $response = $this->getJson("/api/v1/accounting/reports/cash-flow?date_from={$today}&date_to={$today}")->assertOk();

        $response->assertJsonPath('data.opening', 0);
        $response->assertJsonPath('data.student_payments', 100);
        $response->assertJsonPath('data.closing', 100);
    }
}
