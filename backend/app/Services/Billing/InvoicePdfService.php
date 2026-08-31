<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * The only PDF library in the project (barryvdh/laravel-dompdf, added for
 * this feature — nothing existed before it, see the architecture notes).
 * Renders straight from the tenant's own School Settings (name/logo/
 * address/phone/email) — never hard-coded — so every school's invoice
 * looks like their own school's, with zero per-tenant code.
 */
final class InvoicePdfService
{
    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing(['items.product', 'items.variant', 'student', 'tenant', 'payments' => fn ($q) => $q->completed()->orderBy('payment_date')]);

        return Pdf::loadView('pdf.invoice', ['invoice' => $invoice, 'tenant' => $invoice->tenant])
            ->setPaper('a4')
            ->output();
    }

    public function filename(Invoice $invoice): string
    {
        return $invoice->invoice_number.'.pdf';
    }
}
