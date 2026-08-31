<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Jobs\SendInvoiceNotificationJob;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\NotificationLog;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Student;
use App\Support\Audit\AuditAction;
use App\Support\Authorization\Permissions;
use App\Support\Billing\NotificationChannelName;
use App\Support\Billing\NotificationStatus;
use App\Support\Billing\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class InvoiceNotificationTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private function invoiceOf(float $price): Invoice
    {
        $student = Student::factory()->forTenant($this->tenant)->create();
        $product = Product::factory()->forTenant($this->tenant)->create(['price' => $price]);
        $id = $this->postJson('/api/v1/invoices', [
            'student_id' => $student->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.id');

        return Invoice::findOrFail($id);
    }

    public function test_sending_an_invoice_queues_a_job_and_requires_the_notifications_send_permission(): void
    {
        Queue::fake();
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_CREATE]);
        $invoice = $this->invoiceOf(50);

        $this->postJson("/api/v1/invoices/{$invoice->id}/send", [
            'channel' => NotificationChannelName::EMAIL,
            'recipient' => 'parent@example.com',
        ])->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_sending_an_invoice_by_email_queues_the_notification_job(): void
    {
        Queue::fake();
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_CREATE, Permissions::NOTIFICATIONS_SEND]);
        $invoice = $this->invoiceOf(50);

        $this->postJson("/api/v1/invoices/{$invoice->id}/send", [
            'channel' => NotificationChannelName::EMAIL,
            'recipient' => 'parent@example.com',
        ])->assertOk();

        Queue::assertPushed(SendInvoiceNotificationJob::class);
    }

    public function test_a_successful_email_send_is_logged_as_sent_with_an_audit_entry(): void
    {
        Mail::fake();
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_CREATE]);
        $invoice = $this->invoiceOf(50);

        app(\App\Services\Billing\InvoiceNotificationService::class)
            ->send($invoice, 'parent@example.com', NotificationChannelName::EMAIL, $this->admin);

        $log = NotificationLog::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(NotificationStatus::SENT, $log->status);
        $this->assertNotNull($log->sent_at);

        $audit = AuditLog::where('auditable_type', Invoice::class)
            ->where('auditable_id', $invoice->id)
            ->where('action', AuditAction::INVOICE_SENT)
            ->firstOrFail();
        $this->assertStringContainsString('parent@example.com', $audit->description);
    }

    public function test_a_telegram_failure_does_not_roll_back_the_invoice_or_an_existing_payment(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'chat not found'], 400)]);

        $this->actingAsAdminWithPermissions([Permissions::INVOICES_CREATE, Permissions::PAYMENTS_CREATE]);
        $invoice = $this->invoiceOf(50);

        $this->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => 50,
            'payment_method' => PaymentMethod::CASH,
        ])->assertCreated();

        app(\App\Services\Billing\InvoiceNotificationService::class)
            ->send($invoice->fresh(), '123456', NotificationChannelName::TELEGRAM, $this->admin);

        $log = NotificationLog::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(NotificationStatus::FAILED, $log->status);
        $this->assertNotNull($log->error_message);

        // The invoice and its payment are completely unaffected by the
        // notification failure — this is the class rule under test.
        $invoice->refresh();
        $this->assertSame(50.0, (float) $invoice->paid_amount);
        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());

        $audit = AuditLog::where('auditable_type', Invoice::class)
            ->where('auditable_id', $invoice->id)
            ->where('action', AuditAction::INVOICE_SEND_FAILED)
            ->firstOrFail();
        $this->assertNotNull($audit);
    }

    public function test_resending_creates_a_new_notification_log_rather_than_overwriting_the_previous_one(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 400)]);
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_CREATE]);
        $invoice = $this->invoiceOf(50);

        $service = app(\App\Services\Billing\InvoiceNotificationService::class);
        $service->send($invoice, '123456', NotificationChannelName::TELEGRAM, $this->admin);
        $service->send($invoice, '123456', NotificationChannelName::TELEGRAM, $this->admin);

        $this->assertSame(2, NotificationLog::where('invoice_id', $invoice->id)->count());
    }

    public function test_a_missing_telegram_token_fails_gracefully_without_storing_it_anywhere(): void
    {
        config(['services.telegram.bot_token' => null]);
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_CREATE]);
        $invoice = $this->invoiceOf(50);

        app(\App\Services\Billing\InvoiceNotificationService::class)
            ->send($invoice, '123456', NotificationChannelName::TELEGRAM, $this->admin);

        $log = NotificationLog::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(NotificationStatus::FAILED, $log->status);
        $this->assertStringContainsString('not configured', $log->error_message);
    }
}
