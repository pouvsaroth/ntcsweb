<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Product;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use App\Support\Billing\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class BillingDashboardTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_the_dashboard_summary_reflects_todays_sales_and_payments(): void
    {
        $this->actingAsAdminWithPermissions([
            Permissions::INVOICES_CREATE, Permissions::PAYMENTS_CREATE, Permissions::BILLING_REPORTS_VIEW,
        ]);
        $student = Student::factory()->forTenant($this->tenant)->create();
        $product = Product::factory()->forTenant($this->tenant)->create(['price' => 40]);

        $invoiceId = $this->postJson('/api/v1/invoices', [
            'student_id' => $student->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", [
            'amount' => 15,
            'payment_method' => PaymentMethod::CASH,
        ])->assertCreated();

        $response = $this->getJson('/api/v1/billing/dashboard')->assertOk();
        $response->assertJsonPath('data.todays_sales', 40);
        $response->assertJsonPath('data.todays_payments', 15);
        $response->assertJsonPath('data.outstanding', 25);
        $response->assertJsonPath('data.invoice_counts.partial', 1);
    }

    public function test_the_dashboard_requires_the_billing_reports_view_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/v1/billing/dashboard')->assertForbidden();
    }

    public function test_payments_by_method_aggregates_correctly(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_CREATE, Permissions::PAYMENTS_CREATE, Permissions::BILLING_REPORTS_VIEW]);
        $student = Student::factory()->forTenant($this->tenant)->create();
        $product = Product::factory()->forTenant($this->tenant)->create(['price' => 100]);

        $invoiceId = $this->postJson('/api/v1/invoices', [
            'student_id' => $student->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", ['amount' => 30, 'payment_method' => PaymentMethod::CASH])->assertCreated();
        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", ['amount' => 20, 'payment_method' => PaymentMethod::CASH])->assertCreated();

        $response = $this->getJson('/api/v1/billing/reports/payments-by-method')->assertOk();
        $rows = collect($response->json('data'))->keyBy('payment_method');

        $this->assertSame(2, $rows['CASH']['count']);
        $this->assertEqualsWithDelta(50.0, $rows['CASH']['total'], 0.001);
    }
}
