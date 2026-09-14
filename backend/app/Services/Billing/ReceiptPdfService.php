<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Payment;
use App\Services\Pdf\BrowsershotRenderer;
use App\Services\Pdf\KhmerFont;
use App\Services\Pdf\PdfImageEncoder;
use App\Support\Tenancy\TenantContext;

/**
 * A5, same signature/stamp treatment as InvoicePdfService — see that class's
 * own docblock for why this renders via Browsershot rather than dompdf
 * (dompdf can't shape Khmer script) and why the signature is whoever's
 * account actually recorded the payment (Payment::receivedBy), not a fixed
 * signer.
 */
final class ReceiptPdfService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly BrowsershotRenderer $renderer,
    ) {}

    public function render(Payment $payment): string
    {
        $payment->loadMissing(['invoice.student', 'receivedBy.staff.position']);

        // `invoices`/`payments` live in the tenant database now, so neither
        // carries a tenant() relation anymore — see InvoicePdfService for
        // the identical reasoning.
        $tenant = $this->context->getOrFail();
        $issuerStaff = $payment->receivedBy?->staff;

        $html = view('pdf.receipt', [
            'payment' => $payment,
            'invoice' => $payment->invoice,
            'tenant' => $tenant,
            'issuerStaff' => $issuerStaff,
            'logoDataUri' => PdfImageEncoder::dataUri($tenant->logoPath()),
            'stampDataUri' => PdfImageEncoder::dataUri($tenant->stampPath()),
            'signatureDataUri' => PdfImageEncoder::dataUri($issuerStaff?->signaturePath()),
            'khmerFontRegular' => KhmerFont::dataUri('Regular'),
            'khmerFontBold' => KhmerFont::dataUri('Bold'),
        ])->render();

        return $this->renderer->render($html, 'A5');
    }

    public function filename(Payment $payment): string
    {
        return $payment->payment_number.'.pdf';
    }
}
