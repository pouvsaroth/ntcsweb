<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Services\Pdf\BrowsershotRenderer;
use App\Services\Pdf\KhmerFont;
use App\Services\Pdf\PdfImageEncoder;
use App\Support\Tenancy\TenantContext;

/**
 * Renders straight from the tenant's own School Settings (name/logo/stamp/
 * address/phone/email) — never hard-coded — so every school's invoice looks
 * like their own school's, with zero per-tenant code. A5, matching the
 * printed receipt (see ReceiptPdfService).
 *
 * The signature shown is whichever staff member's account actually created
 * the invoice (Invoice::createdBy — a User — via that user's own linked
 * Staff record), not a fixed "always the director" signer: see
 * EnrollmentService/InvoiceService, the only writers of `created_by`.
 */
final class InvoicePdfService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly BrowsershotRenderer $renderer,
    ) {}

    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing([
            'items.product',
            'items.variant',
            'items.reference.schoolClass.schedules',
            'student',
            'payments' => fn ($q) => $q->completed()->orderBy('payment_date'),
            'createdBy.staff.position',
        ]);

        // `invoices` lives in the tenant database now, so it no longer
        // carries its own tenant() relation — the tenant is simply whichever
        // one is already in context for this request/job, exactly the one
        // whose database this Invoice was just read from.
        $tenant = $this->context->getOrFail();
        $issuerStaff = $invoice->createdBy?->staff;

        $html = view('pdf.invoice', [
            'invoice' => $invoice,
            'tenant' => $tenant,
            'issuerStaff' => $issuerStaff,
            'logoDataUri' => PdfImageEncoder::dataUri($tenant->logoPath()),
            'stampDataUri' => PdfImageEncoder::dataUri($tenant->stampPath()),
            'signatureDataUri' => PdfImageEncoder::dataUri($issuerStaff?->signaturePath()),
            'khmerFontRegular' => KhmerFont::dataUri('Regular'),
            'khmerFontBold' => KhmerFont::dataUri('Bold'),
        ])->render();

        return $this->renderer->render($html, 'A5', marginMm: 10);
    }

    public function filename(Invoice $invoice): string
    {
        return $invoice->invoice_number.'.pdf';
    }
}
