<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Billing\PaymentStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recording, cancelling, and refunding payments. Every write here recomputes
 * the parent Invoice inside the same transaction (via InvoiceService), so a
 * Payment can never exist while its invoice's paid_amount/balance/status
 * disagree with it — see the class-level rule in both services' docblocks.
 */
final class PaymentService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly BillingNumberGenerator $numbers,
        private readonly InvoiceService $invoices,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{amount:float, payment_method:string, payment_date?:string, reference_number?:string|null, notes?:string|null}  $data
     */
    public function record(Invoice $invoice, array $data, User $actor): Payment
    {
        return DB::transaction(function () use ($invoice, $data, $actor) {
            // Locked for the whole transaction: two concurrent payments
            // against the same invoice must never both read the same
            // "amount remaining" and both succeed past it.
            /** @var Invoice $invoice */
            $invoice = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if ($invoice->isClosed()) {
                throw ValidationException::withMessages(['invoice' => 'Cannot record a payment against a cancelled or void invoice.']);
            }

            $amount = round((float) $data['amount'], 2);

            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'The payment amount must be greater than zero.']);
            }

            $alreadyPaid = round((float) $invoice->payments()->completed()->sum('amount'), 2);

            // A small epsilon absorbs float/decimal rounding noise without
            // opening the door to a real overpayment — see the class rule
            // "total payments <= invoice total" (no overpayment support yet).
            if (round($alreadyPaid + $amount - (float) $invoice->total, 2) > 0.01) {
                throw ValidationException::withMessages(['amount' => 'This payment would exceed the invoice total. Remaining balance: '.number_format((float) $invoice->balance, 2)]);
            }

            $tenant = $this->context->getOrFail();

            $payment = Payment::query()->create([
                'payment_number' => $this->numbers->nextPaymentNumber($tenant),
                'invoice_id' => $invoice->id,
                'student_id' => $invoice->student_id,
                'amount' => $amount,
                'payment_method' => $data['payment_method'],
                'payment_date' => $data['payment_date'] ?? now()->toDateString(),
                'reference_number' => $data['reference_number'] ?? null,
                'received_by' => $actor->getKey(),
                'notes' => $data['notes'] ?? null,
            ]);

            $this->invoices->recalculate($invoice);

            $this->audit->log(
                AuditAction::PAYMENT_CREATED,
                'Payments',
                $payment,
                new: [
                    'invoice_number' => $invoice->invoice_number,
                    'amount' => $amount,
                    'payment_method' => $payment->payment_method,
                ],
                description: "Recorded payment {$payment->payment_number} of \${$amount} for invoice {$invoice->invoice_number} via {$payment->payment_method}",
            );

            return $payment->fresh();
        });
    }

    public function cancel(Payment $payment, string $reason, User $actor): Payment
    {
        return $this->close($payment, PaymentStatus::CANCELLED, AuditAction::PAYMENT_CANCELLED, $reason, $actor);
    }

    public function refund(Payment $payment, string $reason, User $actor): Payment
    {
        return $this->close($payment, PaymentStatus::REFUNDED, AuditAction::PAYMENT_REFUNDED, $reason, $actor);
    }

    private function close(Payment $payment, string $status, string $action, string $reason, User $actor): Payment
    {
        return DB::transaction(function () use ($payment, $status, $action, $reason, $actor) {
            /** @var Payment $payment */
            $payment = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if (! $payment->isCounted()) {
                throw ValidationException::withMessages(['status' => 'This payment is already cancelled or refunded.']);
            }

            $payment->update([
                'status' => $status,
                'cancellation_reason' => $reason,
                'cancelled_by' => $actor->getKey(),
                'cancelled_at' => now(),
            ]);

            // Recalculating after the status change means this payment's
            // amount no longer counts toward paid_amount — a cancelled or
            // refunded payment stops affecting the invoice's balance
            // immediately, in the same transaction.
            $invoice = Invoice::query()->whereKey($payment->invoice_id)->lockForUpdate()->firstOrFail();
            $this->invoices->recalculate($invoice);

            $this->audit->log(
                $action,
                'Payments',
                $payment,
                old: ['status' => PaymentStatus::COMPLETED],
                new: ['status' => $status, 'reason' => $reason],
                description: ucfirst(mb_strtolower($status))." payment {$payment->payment_number}: {$reason}",
            );

            return $payment;
        });
    }
}
