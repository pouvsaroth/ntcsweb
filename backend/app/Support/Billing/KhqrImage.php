<?php

declare(strict_types=1);

namespace App\Support\Billing;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use InvalidArgumentException;

/**
 * A KHQR string as a QR image (SVG data URI) for the invoice PDF.
 *
 * Written as one byte segment: the string's bytes go into the QR exactly as
 * stored, like the exam fee screen's QR, so a bank's Khmer merchant name is
 * never re-encoded (see Khqr's docblock for why that matters).
 */
final class KhqrImage
{
    public static function dataUri(string $khqr): string
    {
        $options = new QROptions([
            'eccLevel' => EccLevel::M,
            'outputBase64' => true,
            'addQuietzone' => true,
            'quietzoneSize' => 1,
        ]);

        return (new QRCode($options))->addByteSegment($khqr)->render();
    }

    /**
     * The school's KHQR for what is still owed on an invoice, or null when
     * there is nothing to pay or the school has no valid KHQR set.
     */
    public static function forBalance(?string $template, float $balance, string $currency): ?string
    {
        if (blank($template) || $balance <= 0) {
            return null;
        }

        try {
            return self::dataUri(Khqr::withAmount((string) $template, $balance, $currency));
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
