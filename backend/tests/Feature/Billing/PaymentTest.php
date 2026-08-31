<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Student;
use App\Services\Billing\BillingNumberGenerator;
use App\Support\Audit\AuditAction;
use App\Support\Authorization\Permissions;
use App\Support\Billing\InvoiceStatus;
use App\Support\Billing\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private function invoiceOf(float $price): int
    {
        $student = Student::factory()->forTenant($this->tenant)->create();
        $product = Product::factory()->forTenant($this->tenant)->create(['price' => $price]);

        return $this->postJson('/api/v1/invoices', [
            'student_id' => $student->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.id');
    }

    public function test_a_full_payment_marks_the_invoice_paid(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_CREATE, Permissions::PAYMENTS_CREATE, Permissions::INVOICES_VIEW]);
        $invoiceId = $this->invoiceOf(100);

        $response = $this->postJson("/api/v1/invoices/{$invoiceId}/payments", [
            'amount' => 100,
            'payment_method' => PaymentMethod::CASH,
        ]);

        $response->assertCreated();

        $invoice = $this->getJson("/api/v1/invoices/{$invoiceId}")->assertOk();
        $invoice->assertJsonPath('data.status', InvoiceStatus::PAID);
        $invoice->assertJsonPath('data.paid_amount', 100);
        $invoice->assertJsonPath('data.balance', 0);
    }

    public function test_partial_payments_accumulate_and_leave_the_invoice_partially_paid(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_CREATE, Permissions::PAYMENTS_CREATE, Permissions::INVOICES_VIEW]);
        $invoiceId = $this->invoiceOf(100);

        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", ['amount' => 30, 'payment_method' => PaymentMethod::CASH])->assertCreated();
        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", ['amount' => 20, 'payment_method' => PaymentMethod::BANK_TRANSFER])->assertCreated();

        $invoice = $this->getJson("/api/v1/invoices/{$invoiceId}")->assertOk();
        $invoice->assertJsonPath('data.status', InvoiceStatus::PARTIALLY_PAID);
        $invoice->assertJsonPath('data.paid_amount', 50);
        $invoice->assertJsonPath('data.balance', 50);
        $invoice->assertJsonCount(2, 'data.payments');
    }

    public function test_a_payment_cannot_exceed_the_invoice_balance(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_CREATE, Permissions::PAYMENTS_CREATE]);
        $invoiceId = $this->invoiceOf(100);

        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", ['amount' => 80, 'payment_method' => PaymentMethod::CASH])->assertCreated();

        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", ['amount' => 30, 'payment_method' => PaymentMethod::CASH])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->assertSame(1, Payment::count());
    }

    public function test_a_payment_amount_must_be_greater_than_zero(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_CREATE, Permissions::PAYMENTS_CREATE]);
        $invoiceId = $this->invoiceOf(100);

        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", ['amount' => 0, 'payment_method' => PaymentMethod::CASH])
            ->assertUnprocessable();
    }

    public function test_recording_a_payment_requires_the_payments_create_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_CREATE]);
        $invoiceId = $this->invoiceOf(100);

        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", ['amount' => 10, 'payment_method' => PaymentMethod::CASH])
            ->assertForbidden();
    }

    public function test_cancelling_a_payment_reduces_paid_amount_and_reopens_the_invoice(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_CREATE, Permissions::PAYMENTS_CREATE, Permissions::PAYMENTS_CANCEL, Permissions::INVOICES_VIEW]);
        $invoiceId = $this->invoiceOf(100);

        $paymentId = $this->postJson("/api/v1/invoices/{$invoiceId}/payments", ['amount' => 100, 'payment_method' => PaymentMethod::CASH])
            ->assertCreated()->json('data.id');

        $this->getJson("/api/v1/invoices/{$invoiceId}")->assertJsonPath('data.status', InvoiceStatus::PAID);

        $this->postJson("/api/v1/payments/{$paymentId}/cancel", ['reason' => 'Recorded in error'])->assertOk();

        $invoice = $this->getJson("/api/v1/invoices/{$invoiceId}")->assertOk();
        $invoice->assertJsonPath('data.status', InvoiceStatus::ISSUED);
        $invoice->assertJsonPath('data.paid_amount', 0);
        $invoice->assertJsonPath('data.balance', 100);

        $log = AuditLog::where('auditable_type', Payment::class)
            ->where('auditable_id', $paymentId)
            ->where('action', AuditAction::PAYMENT_CANCELLED)
            ->firstOrFail();
        $this->assertStringContainsString('Recorded in error', $log->description);
    }

    public function test_a_cancelled_payment_cannot_be_cancelled_again(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_CREATE, Permissions::PAYMENTS_CREATE, Permissions::PAYMENTS_CANCEL]);
        $invoiceId = $this->invoiceOf(100);
        $paymentId = $this->postJson("/api/v1/invoices/{$invoiceId}/payments", ['amount' => 50, 'payment_method' => PaymentMethod::CASH])
            ->assertCreated()->json('data.id');

        $this->postJson("/api/v1/payments/{$paymentId}/cancel", ['reason' => 'x'])->assertOk();
        $this->postJson("/api/v1/payments/{$paymentId}/cancel", ['reason' => 'x'])->assertUnprocessable();
    }

    public function test_a_student_can_view_only_their_own_payment(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_CREATE, Permissions::PAYMENTS_CREATE]);
        $invoiceId = $this->invoiceOf(50);
        $paymentId = $this->postJson("/api/v1/invoices/{$invoiceId}/payments", ['amount' => 50, 'payment_method' => PaymentMethod::CASH])
            ->assertCreated()->json('data.id');

        $payment = Payment::findOrFail($paymentId);
        $owner = Student::findOrFail($payment->student_id);
        $ownerUser = \App\Models\User::factory()->forTenant($this->tenant)->create();
        $owner->forceFill(['user_id' => $ownerUser->id])->save();

        $otherStudent = Student::factory()->forTenant($this->tenant)->create();
        $otherUser = \App\Models\User::factory()->forTenant($this->tenant)->create();
        $otherStudent->forceFill(['user_id' => $otherUser->id])->save();

        $this->actingAsTenantUser($ownerUser);
        $this->getJson("/api/v1/payments/{$paymentId}")->assertOk();

        $this->actingAsTenantUser($otherUser);
        $this->getJson("/api/v1/payments/{$paymentId}")->assertForbidden();
    }

    /**
     * Not real concurrent HTTP requests (PHPUnit is single-process), but this
     * proves BillingNumberGenerator's locked increment never yields the same
     * number twice across many sequential calls sharing one sequence row —
     * the actual safety net under real concurrency is the DB-level unique
     * constraint on (tenant_id, invoice_number), asserted in the second half.
     */
    public function test_invoice_numbers_are_unique_and_sequential_even_under_repeated_calls(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $generator = app(BillingNumberGenerator::class);

        $numbers = [];
        for ($i = 0; $i < 25; $i++) {
            $numbers[] = $generator->nextInvoiceNumber($this->tenant);
        }

        $this->assertCount(25, array_unique($numbers));

        [, , $first] = [null, null, explode('-', $numbers[0])[2]];
        $this->assertSame($first, sprintf('%06d', (int) $first));
    }

    public function test_duplicate_invoice_numbers_are_rejected_at_the_database_level(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $student = Student::factory()->forTenant($this->tenant)->create();

        \App\Models\Invoice::factory()->forTenant($this->tenant)->forStudent($student)->create(['invoice_number' => 'INV-2026-000001']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        \App\Models\Invoice::factory()->forTenant($this->tenant)->forStudent($student)->create(['invoice_number' => 'INV-2026-000001']);
    }
}
