<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Models\FinancialTransaction;
use App\Models\Product;
use App\Models\Student;
use App\Support\Accounting\TransactionType;
use App\Support\Authorization\Permissions;
use App\Support\Billing\PaymentMethod;
use App\Support\Billing\ProductType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasChartOfAccounts;
use Tests\TestCase;

class RevenueRecognitionTest extends TestCase
{
    use HasAcademicAdmin, HasChartOfAccounts, RefreshDatabase;

    private function createInvoiceAndPay(array $items, float $amount): int
    {
        $student = Student::factory()->forTenant($this->tenant)->create();

        $invoiceId = $this->postJson('/api/v1/invoices', [
            'student_id' => $student->id,
            'items' => $items,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", [
            'amount' => $amount,
            'payment_method' => PaymentMethod::CASH,
        ])->assertCreated();

        return $invoiceId;
    }

    public function test_a_single_item_payment_recognizes_revenue_in_the_products_account(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_CREATE, Permissions::PAYMENTS_CREATE]);
        $this->setUpChartOfAccounts();

        $course = Product::factory()->forTenant($this->tenant)->create(['type' => ProductType::COURSE_FEE, 'price' => 100]);
        $this->createInvoiceAndPay([['product_id' => $course->id, 'quantity' => 1]], 100);

        $this->assertSame(1, FinancialTransaction::where('type', TransactionType::INCOME)->count());
        $transaction = FinancialTransaction::where('type', TransactionType::INCOME)->firstOrFail();

        $this->assertSame($this->cashAccount->id, $transaction->debit_account_id);
        $this->assertSame($this->courseFeesAccount->id, $transaction->credit_account_id);
        $this->assertSame('100.00', (string) $transaction->amount);
    }

    /** Reproduces the spec's own worked example: Course $100 + Book $15 + T-Shirt $10 -> $125 split across 3 revenue accounts. */
    public function test_a_multi_item_payment_splits_revenue_across_each_products_account(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_CREATE, Permissions::PAYMENTS_CREATE]);
        $this->setUpChartOfAccounts();

        $course = Product::factory()->forTenant($this->tenant)->create(['type' => ProductType::COURSE_FEE, 'price' => 100]);
        $book = Product::factory()->forTenant($this->tenant)->create(['type' => ProductType::BOOK, 'price' => 15]);
        $tshirt = Product::factory()->forTenant($this->tenant)->create(['type' => ProductType::T_SHIRT, 'price' => 10]);

        $this->createInvoiceAndPay([
            ['product_id' => $course->id, 'quantity' => 1],
            ['product_id' => $book->id, 'quantity' => 1],
            ['product_id' => $tshirt->id, 'quantity' => 1],
        ], 125);

        $byAccount = FinancialTransaction::where('type', TransactionType::INCOME)->get()->keyBy('credit_account_id');

        $this->assertSame(3, $byAccount->count());
        $this->assertSame('100.00', (string) $byAccount[$this->courseFeesAccount->id]->amount);
        $this->assertSame('15.00', (string) $byAccount[$this->bookSalesAccount->id]->amount);
        $this->assertSame('10.00', (string) $byAccount[$this->tshirtSalesAccount->id]->amount);

        foreach ($byAccount as $transaction) {
            $this->assertSame($this->cashAccount->id, $transaction->debit_account_id);
        }
    }

    public function test_partial_payments_recognize_revenue_proportionally(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_CREATE, Permissions::PAYMENTS_CREATE]);
        $this->setUpChartOfAccounts();

        $course = Product::factory()->forTenant($this->tenant)->create(['type' => ProductType::COURSE_FEE, 'price' => 80]);
        $book = Product::factory()->forTenant($this->tenant)->create(['type' => ProductType::BOOK, 'price' => 20]);

        $student = Student::factory()->forTenant($this->tenant)->create();
        $invoiceId = $this->postJson('/api/v1/invoices', [
            'student_id' => $student->id,
            'items' => [
                ['product_id' => $course->id, 'quantity' => 1],
                ['product_id' => $book->id, 'quantity' => 1],
            ],
        ])->assertCreated()->json('data.id');

        // 50% of the $100 invoice paid -> 50% of each item's revenue recognized.
        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", ['amount' => 50, 'payment_method' => PaymentMethod::CASH])->assertCreated();

        $byAccount = FinancialTransaction::where('type', TransactionType::INCOME)->get()->keyBy('credit_account_id');
        $this->assertSame('40.00', (string) $byAccount[$this->courseFeesAccount->id]->amount);
        $this->assertSame('10.00', (string) $byAccount[$this->bookSalesAccount->id]->amount);
    }

    public function test_a_product_without_a_type_mapping_falls_back_to_the_tenants_default_revenue_account(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_CREATE, Permissions::PAYMENTS_CREATE]);
        $this->setUpChartOfAccounts();

        $misc = Product::factory()->forTenant($this->tenant)->create(['type' => ProductType::OTHER, 'price' => 5]);
        $this->createInvoiceAndPay([['product_id' => $misc->id, 'quantity' => 1]], 5);

        $transaction = FinancialTransaction::where('type', TransactionType::INCOME)->firstOrFail();
        $this->assertSame($this->otherIncomeAccount->id, $transaction->credit_account_id);
    }

    public function test_a_product_with_an_explicit_revenue_account_override_is_used_over_the_type_default(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_CREATE, Permissions::PAYMENTS_CREATE]);
        $this->setUpChartOfAccounts();

        $course = Product::factory()->forTenant($this->tenant)->create([
            'type' => ProductType::COURSE_FEE,
            'price' => 100,
            'revenue_account_id' => $this->bookSalesAccount->id,
        ]);
        $this->createInvoiceAndPay([['product_id' => $course->id, 'quantity' => 1]], 100);

        $transaction = FinancialTransaction::where('type', TransactionType::INCOME)->firstOrFail();
        $this->assertSame($this->bookSalesAccount->id, $transaction->credit_account_id);
    }

    public function test_a_payment_never_creates_revenue_twice(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_CREATE, Permissions::PAYMENTS_CREATE]);
        $this->setUpChartOfAccounts();

        $course = Product::factory()->forTenant($this->tenant)->create(['type' => ProductType::COURSE_FEE, 'price' => 100]);
        $student = Student::factory()->forTenant($this->tenant)->create();

        $invoiceId = $this->postJson('/api/v1/invoices', [
            'student_id' => $student->id,
            'items' => [['product_id' => $course->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.id');

        $payment = \App\Models\Payment::query()->create([
            'payment_number' => 'RCPT-TEST-1',
            'invoice_id' => $invoiceId,
            'student_id' => $student->id,
            'amount' => 100,
            'payment_method' => PaymentMethod::CASH,
            'payment_date' => now()->toDateString(),
            'received_by' => $this->admin->id,
        ]);

        $service = app(\App\Services\Accounting\FinancialTransactionService::class);
        $service->recognizeIncomeForPayment($payment, $this->admin);
        $service->recognizeIncomeForPayment($payment, $this->admin);

        $this->assertSame(1, FinancialTransaction::where('reference_id', $payment->id)->where('reference_type', \App\Models\Payment::class)->count());
    }

    public function test_a_cancelled_payment_reverses_revenue_back_to_zero(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_CREATE, Permissions::PAYMENTS_CREATE, Permissions::PAYMENTS_CANCEL]);
        $this->setUpChartOfAccounts();

        $course = Product::factory()->forTenant($this->tenant)->create(['type' => ProductType::COURSE_FEE, 'price' => 100]);
        $invoiceId = $this->createInvoiceAndPay([['product_id' => $course->id, 'quantity' => 1]], 100);

        $paymentId = \App\Models\Payment::where('invoice_id', $invoiceId)->firstOrFail()->id;
        $this->postJson("/api/v1/payments/{$paymentId}/cancel", ['reason' => 'test'])->assertOk();

        $reports = app(\App\Services\Accounting\AccountingReportService::class);
        $this->assertSame(0.0, $reports->totalRevenue());
        $this->assertSame(0.0, $reports->cashBalance());

        $reversal = FinancialTransaction::where('type', \App\Support\Accounting\TransactionType::ADJUSTMENT)->firstOrFail();
        $this->assertSame($this->courseFeesAccount->id, $reversal->debit_account_id);
        $this->assertSame($this->cashAccount->id, $reversal->credit_account_id);
    }

    public function test_a_cancelled_or_failed_payment_never_gets_recognized_in_the_first_place(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_CREATE, Permissions::PAYMENTS_CREATE]);
        $this->setUpChartOfAccounts();

        $course = Product::factory()->forTenant($this->tenant)->create(['type' => ProductType::COURSE_FEE, 'price' => 100]);
        $student = Student::factory()->forTenant($this->tenant)->create();
        $this->postJson('/api/v1/invoices', [
            'student_id' => $student->id,
            'items' => [['product_id' => $course->id, 'quantity' => 1]],
        ])->assertCreated();

        // No payment recorded at all — nothing should ever be recognized.
        $this->assertSame(0, FinancialTransaction::count());
    }

    public function test_billing_keeps_working_when_accounting_is_not_configured(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_CREATE, Permissions::PAYMENTS_CREATE]);
        // Deliberately skip setUpChartOfAccounts() — no accounts, no settings.

        $course = Product::factory()->forTenant($this->tenant)->create(['type' => ProductType::COURSE_FEE, 'price' => 100]);
        $this->createInvoiceAndPay([['product_id' => $course->id, 'quantity' => 1]], 100);

        $this->assertSame(0, FinancialTransaction::count());
        $this->assertSame(1, \App\Models\Payment::count());
    }
}
