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

class AccountingPeriodTest extends TestCase
{
    use HasAcademicAdmin, HasChartOfAccounts, RefreshDatabase;

    public function test_closing_a_period_blocks_a_payment_dated_inside_it(): void
    {
        $this->actingAsAdminWithPermissions([
            Permissions::INVOICES_CREATE, Permissions::PAYMENTS_CREATE, Permissions::ACCOUNTING_PERIOD_CLOSE,
        ]);
        $this->setUpChartOfAccounts();

        $lastMonth = now()->subMonthNoOverflow();
        $this->postJson('/api/v1/accounting/periods/close', ['period' => $lastMonth->format('Y-m')])->assertCreated();

        $course = Product::factory()->forTenant($this->tenant)->create(['type' => ProductType::COURSE_FEE, 'price' => 100]);
        $student = Student::factory()->forTenant($this->tenant)->create();
        $invoiceId = $this->postJson('/api/v1/invoices', [
            'student_id' => $student->id,
            'items' => [['product_id' => $course->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.id');

        $response = $this->postJson("/api/v1/invoices/{$invoiceId}/payments", [
            'amount' => 100,
            'payment_method' => PaymentMethod::CASH,
            'payment_date' => $lastMonth->toDateString(),
        ]);

        $response->assertUnprocessable();
    }

    public function test_a_period_cannot_be_closed_twice(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ACCOUNTING_PERIOD_CLOSE]);

        $period = now()->format('Y-m');
        $this->postJson('/api/v1/accounting/periods/close', ['period' => $period])->assertCreated();
        $this->postJson('/api/v1/accounting/periods/close', ['period' => $period])->assertUnprocessable();
    }

    public function test_closing_a_period_requires_the_accounting_period_close_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->postJson('/api/v1/accounting/periods/close', ['period' => now()->format('Y-m')])->assertForbidden();
    }
}
