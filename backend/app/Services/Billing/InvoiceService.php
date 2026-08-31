<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Billing\InvoiceStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one place an Invoice's financial figures are computed. Nothing else —
 * no controller, no request — is allowed to set subtotal/total/paid_amount/
 * balance directly; see recalculate().
 *
 * Invoice/InvoiceItem/Payment don't use the Auditable trait, so every
 * meaningful action here fires its own explicit, richly-described audit
 * entry (AuditAction's billing section) instead of a generic CREATE/UPDATE.
 */
final class InvoiceService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly BillingNumberGenerator $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{student_id:int, invoice_date?:string, due_date?:string|null, discount?:float, tax?:float, notes?:string|null, items:list<array<string,mixed>>}  $data
     */
    public function create(array $data, User $actor): Invoice
    {
        return DB::transaction(function () use ($data, $actor) {
            $tenant = $this->context->getOrFail();

            $invoice = Invoice::query()->create([
                'invoice_number' => $this->numbers->nextInvoiceNumber($tenant),
                'student_id' => $data['student_id'],
                'invoice_date' => $data['invoice_date'] ?? now()->toDateString(),
                'due_date' => $data['due_date'] ?? null,
                'status' => InvoiceStatus::ISSUED,
                'discount' => (float) ($data['discount'] ?? 0),
                'tax' => (float) ($data['tax'] ?? 0),
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->getKey(),
            ]);

            foreach ($data['items'] as $itemData) {
                $this->addItem($invoice, $itemData);
            }

            $this->recalculate($invoice);
            $invoice->refresh();

            $this->audit->log(
                AuditAction::INVOICE_CREATED,
                'Invoices',
                $invoice,
                new: [
                    'student_id' => $invoice->student_id,
                    'items' => $invoice->items->count(),
                    'subtotal' => (float) $invoice->subtotal,
                    'discount' => (float) $invoice->discount,
                    'tax' => (float) $invoice->tax,
                    'total' => (float) $invoice->total,
                ],
                description: "Created invoice {$invoice->invoice_number} — total \${$invoice->total}",
            );

            return $invoice->load(['items.product', 'items.variant', 'student']);
        });
    }

    /**
     * @param  array{product_id:int, product_variant_id?:int|null, quantity?:int, unit_price?:float|null, discount?:float, description?:string|null, reference_type?:string|null, reference_id?:int|null}  $itemData
     */
    private function addItem(Invoice $invoice, array $itemData): InvoiceItem
    {
        $product = Product::query()->findOrFail($itemData['product_id']);
        $variant = isset($itemData['product_variant_id']) && $itemData['product_variant_id'] !== null
            ? ProductVariant::query()->where('product_id', $product->id)->findOrFail($itemData['product_variant_id'])
            : null;

        $quantity = max(1, (int) ($itemData['quantity'] ?? 1));

        // A caller may override the catalog price (e.g. a negotiated fee) —
        // otherwise the variant's/product's current price is snapshotted
        // here and never re-read live again; see this model's migration.
        $unitPrice = round((float) ($itemData['unit_price'] ?? ($variant?->effectivePrice() ?? $product->price)), 2);
        $discount = round((float) ($itemData['discount'] ?? 0), 2);
        $subtotal = round($quantity * $unitPrice, 2);
        $total = max(0, round($subtotal - $discount, 2));

        $description = $itemData['description']
            ?? ($variant !== null ? "{$product->name} - {$variant->name}" : $product->name);

        return InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount' => $discount,
            'subtotal' => $subtotal,
            'total' => $total,
            'reference_type' => $itemData['reference_type'] ?? null,
            'reference_id' => $itemData['reference_id'] ?? null,
        ]);
    }

    /**
     * subtotal = sum of each item's own (already net-of-item-discount) total.
     * invoice.discount is a further, invoice-level adjustment on top of that
     * — see the class docblock. Recomputes paid_amount from COMPLETED
     * payments (never trusts a stored value) and derives status from the
     * result, unless the invoice is already in a closed (CANCELLED/VOID)
     * state, which nothing here may override.
     */
    public function recalculate(Invoice $invoice): Invoice
    {
        $items = $invoice->items()->get();
        $subtotal = round((float) $items->sum('total'), 2);
        $total = max(0, round($subtotal - (float) $invoice->discount + (float) $invoice->tax, 2));
        $paid = round((float) $invoice->payments()->completed()->sum('amount'), 2);
        $balance = round($total - $paid, 2);

        $invoice->update([
            'subtotal' => $subtotal,
            'total' => $total,
            'paid_amount' => $paid,
            'balance' => $balance,
            'status' => $this->deriveStatus($invoice, $total, $paid, $balance),
        ]);

        return $invoice;
    }

    private function deriveStatus(Invoice $invoice, float $total, float $paid, float $balance): string
    {
        if ($invoice->isClosed()) {
            return $invoice->status;
        }

        if ($total > 0 && $balance <= 0.004) {
            return InvoiceStatus::PAID;
        }

        if ($paid > 0 && $balance > 0.004) {
            return InvoiceStatus::PARTIALLY_PAID;
        }

        if ($invoice->due_date !== null && $invoice->due_date->isPast() && $balance > 0.004) {
            return InvoiceStatus::OVERDUE;
        }

        return InvoiceStatus::ISSUED;
    }

    public function cancel(Invoice $invoice, string $reason, User $actor): Invoice
    {
        return $this->close($invoice, InvoiceStatus::CANCELLED, AuditAction::INVOICE_CANCELLED, $reason, $actor);
    }

    public function void(Invoice $invoice, string $reason, User $actor): Invoice
    {
        return $this->close($invoice, InvoiceStatus::VOID, AuditAction::INVOICE_VOIDED, $reason, $actor);
    }

    private function close(Invoice $invoice, string $status, string $action, string $reason, User $actor): Invoice
    {
        return DB::transaction(function () use ($invoice, $status, $action, $reason, $actor) {
            /** @var Invoice $invoice */
            $invoice = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if ($invoice->isClosed()) {
                throw ValidationException::withMessages(['status' => 'This invoice is already cancelled or void.']);
            }

            // A paid invoice needs a refund, not a cancellation — cancelling
            // it here would silently make paid_amount/balance stop meaning
            // anything for a "closed" invoice.
            if ((float) $invoice->paid_amount > 0) {
                throw ValidationException::withMessages(['status' => 'An invoice with payments recorded cannot be cancelled or voided — cancel the payment(s) first.']);
            }

            $invoice->update([
                'status' => $status,
                'cancellation_reason' => $reason,
                'cancelled_by' => $actor->getKey(),
                'cancelled_at' => now(),
            ]);

            $this->audit->log(
                $action,
                'Invoices',
                $invoice,
                old: ['status' => InvoiceStatus::ISSUED],
                new: ['status' => $status, 'reason' => $reason],
                description: ucfirst(mb_strtolower($status))." invoice {$invoice->invoice_number}: {$reason}",
            );

            return $invoice;
        });
    }
}
