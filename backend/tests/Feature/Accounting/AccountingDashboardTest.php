<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Models\Product;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use App\Support\Billing\PaymentMethod;
use App\Support\Billing\ProductType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasChartOfAccounts;
use Tests\TestCase;

class AccountingDashboardTest extends TestCase
{
    use HasAcademicAdmin, HasChartOfAccounts, RefreshDatabase;

    public function test_the_dashboard_reflects_revenue_expenses_and_cash_balance(): void
    {
        $this->actingAsAdminWithPermissions([
            Permissions::INVOICES_CREATE, Permissions::PAYMENTS_CREATE, Permissions::ACCOUNTING_DASHBOARD_VIEW,
        ]);
        $this->setUpChartOfAccounts();

        $course = Product::factory()->forTenant($this->tenant)->create(['type' => ProductType::COURSE_FEE, 'price' => 100]);
        $student = Student::factory()->forTenant($this->tenant)->create();
        $invoiceId = $this->postJson('/api/v1/invoices', [
            'student_id' => $student->id,
            'items' => [['product_id' => $course->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", ['amount' => 100, 'payment_method' => PaymentMethod::CASH])->assertCreated();

        $response = $this->getJson('/api/v1/accounting/dashboard')->assertOk();
        $response->assertJsonPath('data.total_revenue', 100);
        $response->assertJsonPath('data.total_expenses', 0);
        $response->assertJsonPath('data.net_profit', 100);
        $response->assertJsonPath('data.todays_income', 100);
        $response->assertJsonPath('data.total_cash_balance', 100);
    }

    public function test_the_dashboard_requires_the_accounting_dashboard_view_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/v1/accounting/dashboard')->assertForbidden();
    }
}
