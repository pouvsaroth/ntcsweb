<?php

declare(strict_types=1);

namespace App\Platform\Billing;

use App\Platform\Models\BillingSettings;
use App\Platform\Models\SchoolPayment;
use App\Services\Pdf\BrowsershotRenderer;
use App\Services\Pdf\KhmerFont;
use App\Services\Pdf\PdfImageEncoder;

/**
 * The receipt a school gets for a payment to the platform (stage 5b): A5, the
 * header from Billing settings, VOID across it once voided. Same Browsershot
 * pipeline as the schools' own receipts (Khmer school names shape correctly).
 */
final class PlatformReceiptPdf
{
    public function __construct(private readonly BrowsershotRenderer $renderer) {}

    public function html(SchoolPayment $payment): string
    {
        $payment->loadMissing(['tenant', 'subscription.plan', 'recorder:id,name']);
        $settings = BillingSettings::current();

        return view('pdf.platform-receipt', [
            'payment' => $payment,
            'school' => $payment->tenant,
            'plan' => $payment->subscription?->plan,
            'settings' => $settings,
            'logoDataUri' => PdfImageEncoder::dataUri($settings->logoPath()),
            'khmerFontRegular' => KhmerFont::dataUri('Regular'),
            'khmerFontBold' => KhmerFont::dataUri('Bold'),
        ])->render();
    }

    public function render(SchoolPayment $payment): string
    {
        return $this->renderer->render($this->html($payment), 'A5', 10);
    }

    public function filename(SchoolPayment $payment): string
    {
        return $payment->receipt_number.'.pdf';
    }
}
