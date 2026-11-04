<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Enrollment;
use App\Models\Payment;
use App\Services\Pdf\BrowsershotRenderer;
use App\Services\Pdf\KhmerFont;
use App\Services\Pdf\PdfImageEncoder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\App;

/**
 * A receipt is the invoice template in receipt mode (pdf/receipt.blade.php
 * includes pdf/invoice.blade.php), on the same paper as its invoice, so the
 * two always look alike. Same signature/stamp treatment as InvoicePdfService — see that class's
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
        private readonly InvoicePdfService $invoicePdf,
    ) {}

    /**
     * `$locale` ('en' or 'km', see InvoicePdfService::resolveRequestedLocale())
     * prints this one receipt in that language; null keeps the school's own
     * invoice language. Restored afterwards, as InvoicePdfService does.
     */
    public function render(Payment $payment, ?string $locale = null): string
    {
        return $this->renderer->render($this->html($payment, $locale), $this->paper($payment), marginMm: 10);
    }

    /** The same receipt as an image, sized like the invoice image — what "Pay and invoice" prints. */
    public function renderImage(Payment $payment, ?string $locale = null): string
    {
        [$width, $height] = $this->paper($payment) === 'A4' ? [210, 297] : [148, 210];

        return $this->renderer->renderImage($this->html($payment, $locale), $width, $height, marginMm: 10);
    }

    private function paper(Payment $payment): string
    {
        return $this->invoicePdf->paper($payment->invoice);
    }

    private function html(Payment $payment, ?string $locale): string
    {
        $payment->loadMissing([
            'invoice.items.product',
            'invoice.items.variant',
            // See InvoicePdfService::html() — only an enrollment line has a class to follow.
            'invoice.items.reference' => fn (MorphTo $morph) => $morph->morphWith([Enrollment::class => ['schoolClass.schedules']]),
            'invoice.student',
            'invoice.company',
            'invoice.schoolClass.schedules',
            'invoice.invoiceStudents',
            'invoice.payments' => fn ($q) => $q->completed()->orderBy('id'),
            'receivedBy.staff.position',
        ]);

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
                'khqrDataUri' => null,
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

    public function imageFilename(Payment $payment): string
    {
        return $payment->payment_number.'.png';
    }
}
