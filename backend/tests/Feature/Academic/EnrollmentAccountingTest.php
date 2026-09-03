<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\FinancialTransaction;
use App\Models\Student;
use App\Support\Accounting\TransactionType;
use App\Support\Authorization\Permissions;
use App\Support\Billing\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\Concerns\HasChartOfAccounts;
use Tests\TestCase;

/**
 * Proves the whole point of terminating a package's billing at a real
 * Product row: paying the auto-created invoice posts exactly one revenue
 * transaction via the existing, completely unmodified
 * FinancialTransactionService/RevenueAccountResolver.
 */
class EnrollmentAccountingTest extends TestCase
{
    use HasAcademicAdmin, HasAcademicCatalog, HasChartOfAccounts, RefreshDatabase;

    public function test_paying_a_package_enrollments_invoice_recognizes_exactly_one_revenue_transaction(): void
    {
        $this->actingAsAdminWithPermissions([
            Permissions::ENROLLMENTS_CREATE, Permissions::PAYMENTS_CREATE,
        ]);
        $this->setUpAcademicCatalog();
        $this->setUpChartOfAccounts();
        $student = Student::factory()->forTenant($this->tenant)->create();

        $enrollment = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
        ])->assertCreated()->json('data');

        $invoiceId = \App\Models\InvoiceItem::where('reference_type', \App\Models\Enrollment::class)
            ->where('reference_id', $enrollment['id'])->firstOrFail()->invoice_id;

        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", [
            'amount' => 24,
            'payment_method' => PaymentMethod::CASH,
        ])->assertCreated();

        $this->assertSame(1, FinancialTransaction::where('type', TransactionType::INCOME)->count());

        $transaction = FinancialTransaction::where('type', TransactionType::INCOME)->firstOrFail();
        $this->assertSame($this->cashAccount->id, $transaction->debit_account_id);
        $this->assertSame($this->courseFeesAccount->id, $transaction->credit_account_id);
        $this->assertSame('24.00', (string) $transaction->amount);

        $invoice = \App\Models\Invoice::findOrFail($invoiceId);
        $this->assertSame('24.00', (string) $invoice->paid_amount);
        $this->assertSame('0.00', (string) $invoice->balance);
        $this->assertSame(\App\Support\Billing\InvoiceStatus::PAID, $invoice->status);
    }
}
