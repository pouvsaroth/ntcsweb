<?php

declare(strict_types=1);

namespace App\Services\Pdf;

/**
 * Turns an absolute filesystem path (Tenant::logoPath()/stampPath(),
 * Staff::signaturePath(), ...) into a base64 `data:` URI — see
 * BrowsershotRenderer's own docblock for why a PDF template can't just
 * link to these by URL.
 */
final class PdfImageEncoder
{
    public static function dataUri(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $mime = mime_content_type($path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode(file_get_contents($path));
    }
}
