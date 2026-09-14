<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use Spatie\Browsershot\Browsershot;

/**
 * The one place HTML is turned into a PDF for both invoices and receipts
 * (see InvoicePdfService/ReceiptPdfService) — a headless, system-installed
 * Chromium (see docker/php/Dockerfile) rather than dompdf: dompdf has no
 * real text-shaping engine, so it draws Khmer glyphs one codepoint at a
 * time with no vowel reordering or coeng (subscript consonant) formation,
 * corrupting anything but plain Latin text. Chromium shapes text the same
 * way a real browser tab does, which is the only way to render Khmer (or
 * any complex script) correctly from PHP without hand-rolling a shaping
 * engine.
 *
 * Callers must still embed any image/font as a base64 `data:` URI rather
 * than a `file://`/`http://` link — Browsershot refuses `file://` outright
 * (local-file-disclosure guard, no bypass), and `http://localhost:8080`
 * isn't reachable from inside this container's own network namespace
 * (`localhost` there means the php container itself, not nginx). See
 * PdfImageEncoder for that.
 */
final class BrowsershotRenderer
{
    /**
     * @param  float|null  $marginMm  Same value on all four sides, in millimeters. Null leaves Chromium's own default margin untouched.
     */
    public function render(string $html, string $format = 'A4', ?float $marginMm = null): string
    {
        $home = $this->freshChromiumHome();

        try {
            $browsershot = $this->browser($html, $format, $home);

            if ($marginMm !== null) {
                $browsershot->margins($marginMm, $marginMm, $marginMm, $marginMm);
            }

            return $browsershot->pdf();
        } finally {
            // Best-effort — a leftover directory here is harmless clutter,
            // never worth failing (or even logging) an otherwise-successful
            // render over.
            @exec('rm -rf '.escapeshellarg($home));
        }
    }

    /**
     * A brand-new, never-before-used directory to use as Chromium's HOME
     * for one render — see browser()'s docblock for why a shared one isn't
     * safe. Chromium creates everything under it itself (.config/chromium/
     * etc.); nothing needs to pre-exist beyond the parent temp directory.
     */
    private function freshChromiumHome(): string
    {
        return sys_get_temp_dir().'/browsershot-home-'.bin2hex(random_bytes(8));
    }

    private function browser(string $html, string $format, string $home): Browsershot
    {
        $browsershot = Browsershot::html($html)
            ->format($format)
            ->showBackground()
            ->noSandbox() // running as root (CLI) or www-data (php-fpm) inside the container — Chromium refuses its own sandbox in that context either way.
            ->waitUntilNetworkIdle()
            // php-fpm's www-data user has HOME=/var/www, which it doesn't own and can't write
            // to — Chromium's crash reporter (crashpad) tries to create its database there on
            // launch and dies immediately ("chrome_crashpad_handler: --database is required").
            //
            // A *shared* writable HOME (e.g. plain sys_get_temp_dir()) isn't enough on its
            // own, though: crashpad's database lives under $HOME/.config/chromium, and
            // whichever user's Chromium creates that directory FIRST owns it from then on
            // (mode 0700) — e.g. `docker exec` running a test suite as root, before the real
            // php-fpm/www-data request ever comes in, permanently breaks every later render
            // with this exact error until someone notices and deletes it. $home (see
            // freshChromiumHome()) is generated fresh per render so it can never collide
            // with anything another user already created.
            ->setNodeEnv(['HOME' => $home])
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
