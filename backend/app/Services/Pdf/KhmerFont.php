<?php

declare(strict_types=1);

namespace App\Services\Pdf;

/**
 * The bundled Noto Sans Khmer font, as a base64 `data:` URI — see
 * BrowsershotRenderer's own docblock for why a PDF template can't just
 * `@font-face` a normal file path. Shared between invoice.blade.php and
 * receipt.blade.php; cached per request/process since it never changes.
 */
final class KhmerFont
{
    /** @var array<string, string> */
    private static array $cache = [];

    public static function dataUri(string $weight): string
    {
        return self::$cache[$weight] ??= 'data:font/ttf;base64,'.base64_encode(
            file_get_contents(resource_path("fonts/khmer/NotoSansKhmer-{$weight}.ttf"))
        );
    }
}
