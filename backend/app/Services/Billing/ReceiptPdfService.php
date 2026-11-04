<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Payment;
use App\Services\Pdf\BrowsershotRenderer;
use App\Services\Pdf\KhmerFont;
use App\Services\Pdf\PdfImageEncoder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\App;

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

    /**
     * `$locale` ('en' or 'km', see InvoicePdfService::resolveRequestedLocale())
     * prints this one receipt in that language; null keeps the school's own
     * invoice language. Restored afterwards, as InvoicePdfService does.
     */
    public function render(Payment $payment, ?string $locale = null): string
    {
        return $this->renderer->render($this->html($payment, $locale), 'A5');
    }

    private function html(Payment $payment, ?string $locale): string
    {
        $payment->loadMissing(['invoice.student', 'invoice.company', 'invoice.schoolClass', 'receivedBy.staff.position']);

        // `invoices`/`payments` live in the tenant database now, so neither
        // carries a tenant() relation anymore — see InvoicePdfService for
        // the identical reasoning.
        $tenant = $this->context->getOrFail();
        $issuerStaff = $payment->receivedBy?->staff;

        $previousLocale = App::getLocale();
        if ($locale !== null) {
            App::setLocale($locale);
        }

        try {
            return view('pdf.receipt', [
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
        } finally {
            if ($locale !== null) {
                App::setLocale($previousLocale);
            }
        }
    }

    public function filename(Payment $payment): string
    {
        return $payment->payment_number.'.pdf';
    }
}
