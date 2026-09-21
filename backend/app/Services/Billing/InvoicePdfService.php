<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Services\Pdf\BrowsershotRenderer;
use App\Services\Pdf\KhmerFont;
use App\Services\Pdf\PdfImageEncoder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\App;

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
    /** Every language `resources/lang/{locale}/invoice.php` actually has translations for — see UpdateSchoolSettingsRequest's `locale` rule, which limits School Settings to the same set for the same reason. */
    private const SUPPORTED_LOCALES = ['en', 'km'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly BrowsershotRenderer $renderer,
    ) {}

    /**
     * Turns a requested locale (e.g. the admin UI's own current language,
     * passed as `?locale=` on the download request) into what render() will
     * accept — null for anything unsupported/absent, so callers just fall
     * back to the tenant's own School Settings language rather than erroring
     * over a UI language (Chinese/Korean/Japanese) invoices don't have
     * translations for yet.
     */
    public static function resolveRequestedLocale(?string $raw): ?string
    {
        return in_array($raw, self::SUPPORTED_LOCALES, true) ? $raw : null;
    }

    /**
     * `$locale`, when given, renders this one invoice in that language
     * instead of the tenant's own School Settings "Invoice Language" —
     * whichever language the staff member printing it currently has their
     * own admin UI set to (see InvoiceController::downloadPdf()). Restored
     * afterward regardless of outcome so a one-off print never leaks a
     * different locale into the rest of this request/job — e.g. the emailed
     * copy (InvoiceMail) always wants the tenant's own fixed language, never
     * whichever admin happened to trigger it.
     */
    public function render(Invoice $invoice, ?string $locale = null): string
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

        $previousLocale = App::getLocale();
        if ($locale !== null) {
            App::setLocale($locale);
        }

        try {
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
        } finally {
            if ($locale !== null) {
                App::setLocale($previousLocale);
            }
        }

        return $this->renderer->render($html, 'A5', marginMm: 10);
    }

    public function filename(Invoice $invoice): string
    {
        return $invoice->invoice_number.'.pdf';
    }
}
