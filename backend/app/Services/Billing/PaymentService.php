<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Accounting\FinancialTransactionService;
use App\Support\Accounting\TransactionType;
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
 *
 * Also recognizes/reverses the corresponding ledger entry via
 * FinancialTransactionService, inside the same transaction — a payment and
 * its accounting recognition are never allowed to disagree. That service
 * itself no-ops quietly if Accounting isn't configured yet (no Cash/Bank
 * account set up), so Billing keeps working standalone either way.
 */
final class PaymentService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly BillingNumberGenerator $numbers,
        private readonly InvoiceService $invoices,
        private readonly FinancialTransactionService $accounting,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{amount:float, payment_method:string, payment_date?:string, reference_number?:string|null, notes?:string|null, discount?:float|null, discount_reason?:string|null}  $data
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
            $discount = round((float) ($data['discount'] ?? 0), 2);

            // Must leave something to pay — a discount that settles the
            // whole balance isn't a payment and has nothing to record here.
            if ($discount > 0 && round($discount - (float) $invoice->balance, 2) >= 0) {
                throw ValidationException::withMessages(['discount' => 'The discount must be less than the remaining balance of '.number_format((float) $invoice->balance, 2).'.']);
            }

            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'The payment amount must be greater than zero.']);
            }

            $alreadyPaid = round((float) $invoice->payments()->completed()->sum('amount'), 2);

            // A small epsilon absorbs float/decimal rounding noise without
            // opening the door to a real overpayment — see the class rule
            // "total payments <= invoice total" (no overpayment support yet).
            // Measured against the total *after* this payment's discount.
            // The discount itself is only written once every check has
            // passed: the invoice lives on the tenant connection, which the
            // surrounding DB::transaction() doesn't cover, so a later throw
            // would not roll an earlier invoice write back.
            if (round($alreadyPaid + $amount - ((float) $invoice->total - $discount), 2) > 0.01) {
                throw ValidationException::withMessages(['amount' => 'This payment would exceed the invoice total. Remaining balance: '.number_format((float) $invoice->balance - $discount, 2)]);
            }

            if ($discount > 0) {
                $invoice->update([
                    'discount' => round((float) $invoice->discount + $discount, 2),
                    'discount_reason' => $data['discount_reason'] ?? $invoice->discount_reason,
                ]);
            }

            $tenant = $this->context->getOrFail();

            $payment = Payment::query()->create([
                'payment_number' => $this->numbers->nextPaymentNumber($tenant),
                'invoice_id' => $invoice->id,
                'student_id' => $invoice->student_id,
                'amount' => $amount,
                'currency' => $invoice->currency,
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
                    'currency' => $invoice->currency,
                    'payment_method' => $payment->payment_method,
                    'discount' => $discount,
                    'discount_reason' => $discount > 0 ? ($data['discount_reason'] ?? null) : null,
                ],
                description: "Recorded payment {$payment->payment_number} of {$amount} {$invoice->currency} for invoice {$invoice->invoice_number} via {$payment->payment_method}"
                    .($discount > 0 ? " with a {$discount} {$invoice->currency} discount" : ''),
            );

            $this->accounting->recognizeIncomeForPayment($payment, $actor);

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

            // A cancellation is an accounting correction (the original entry
            // was a mistake); a refund is real money genuinely returned —
            // see TransactionType and FinancialTransactionService::reverse().
            $reversalType = $status === PaymentStatus::REFUNDED ? TransactionType::REFUND : TransactionType::ADJUSTMENT;
            $this->accounting->reverseIncomeForPayment($payment, $reversalType, $actor);

            return $payment;
        });
    }
}
