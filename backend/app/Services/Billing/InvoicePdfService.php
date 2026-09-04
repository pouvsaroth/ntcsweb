<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Tenant;
use Spatie\Browsershot\Browsershot;

/**
 * Renders straight from the tenant's own School Settings (name/logo/
 * address/phone/email) — never hard-coded — so every school's invoice
 * looks like their own school's, with zero per-tenant code.
 *
 * Uses Browsershot (a headless, system-installed Chromium — see
 * docker/php/Dockerfile) rather than dompdf: dompdf has no real text-shaping
 * engine, so it draws Khmer glyphs one codepoint at a time with no vowel
 * reordering or coeng (subscript consonant) formation, corrupting anything
 * but plain Latin text. Chromium shapes text the same way a real browser
 * tab does, which is the only way to render Khmer (or any complex script)
 * correctly from PHP without hand-rolling a shaping engine.
 *
 * The logo and Khmer font are embedded as base64 `data:` URIs rather than
 * linked by URL: Browsershot refuses `file://` anywhere in the HTML
 * (local-file-disclosure guard, no bypass), and `http://localhost:8080`
 * isn't reachable from inside this container's own network namespace
 * (`localhost` there means the php container itself, not nginx). A data:
 * URI sidesteps both — no network hop, no blocked protocol.
 */
final class InvoicePdfService
{
    /** @var array<string, string> */
    private static array $khmerFontCache = [];

    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing(['items.product', 'items.variant', 'student', 'tenant', 'payments' => fn ($q) => $q->completed()->orderBy('payment_date')]);

        $html = view('pdf.invoice', [
            'invoice' => $invoice,
            'tenant' => $invoice->tenant,
            'logoDataUri' => $this->logoDataUri($invoice->tenant),
            'khmerFontRegular' => $this->khmerFontDataUri('Regular'),
            'khmerFontBold' => $this->khmerFontDataUri('Bold'),
        ])->render();

        return $this->browser($html)->pdf();
    }

    public function filename(Invoice $invoice): string
    {
        return $invoice->invoice_number.'.pdf';
    }

    private function logoDataUri(?Tenant $tenant): ?string
    {
        $path = $tenant?->logoPath();

        if ($path === null) {
            return null;
        }

        $mime = mime_content_type($path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode(file_get_contents($path));
    }

    private function khmerFontDataUri(string $weight): string
    {
        return self::$khmerFontCache[$weight] ??= 'data:font/ttf;base64,'.base64_encode(
            file_get_contents(resource_path("fonts/khmer/NotoSansKhmer-{$weight}.ttf"))
        );
    }

    private function browser(string $html): Browsershot
    {
        $browsershot = Browsershot::html($html)
            ->format('A4')
            ->showBackground()
            ->noSandbox() // running as root (CLI) or www-data (php-fpm) inside the container — Chromium refuses its own sandbox in that context either way.
            ->waitUntilNetworkIdle()
            // php-fpm's www-data user has HOME=/var/www, which it doesn't own and can't write
            // to — Chromium's crash reporter (crashpad) tries to create its database there on
            // launch and dies immediately ("chrome_crashpad_handler: --database is required").
            // Pointing HOME at a writable directory fixes it; disabling the reporter outright
            // means one less thing to depend on working inside a container.
            ->setNodeEnv(['HOME' => sys_get_temp_dir()])
            ->addChromiumArguments(['disable-crash-reporter']);

        if ($chromePath = config('services.browsershot.chrome_path')) {
            $browsershot->setChromePath($chromePath);
        }

        if ($nodeModulePath = config('services.browsershot.node_modules_path')) {
            $browsershot->setNodeModulePath($nodeModulePath);
        }

        return $browsershot;
    }
}
