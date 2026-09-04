<?php

declare(strict_types=1);

namespace App\Support\Billing;

use InvalidArgumentException;

/**
 * Turns a school's own static "receive any amount" Bakong KHQR code (the one
 * a bank's app — e.g. ACLEDA Toanchet's "My QR" screen — generates) into a
 * fixed-amount code for one specific invoice, following the same EMVCo TLV
 * format every Bakong-compliant app already reads.
 *
 * Deliberately never parses or re-encodes the merchant name (tag 59) or city
 * (tag 60): a real Cambodian bank QR's Khmer text is commonly stored in a
 * legacy 8-bit Khmer charset, not UTF-8 — its declared TLV length can't be
 * trusted to match a UTF-8 byte count once the string has passed through
 * anything that re-encodes text (a generic TLV walker included, verified by
 * hand against a real ACLEDA KHQR string: the declared length of its Khmer
 * name field does NOT match its UTF-8 byte count, and walking the string
 * with that length corrupts every field after it).
 *
 * Instead, this locates the fixed, always-ASCII "58" + len "02" + "KH"
 * country-code field by literal substring search and splices a new tag 54
 * (Transaction Amount) in immediately before it — the standard EMVCo
 * position (52, 53, 54, ..., 58) — leaving every byte on either side of the
 * insertion point, Khmer text included, completely untouched. Verified
 * against a real template: the untouched suffix (country code onward) comes
 * out byte-for-byte identical to the original.
 */
final class Khqr
{
    private const COUNTRY_ANCHOR = '5802KH';

    private const CRC_TAG = '6304';

    /** ISO 4217 numeric currency codes — the only two this platform bills in (see CoursePackage::CURRENCY_*). */
    private const CURRENCY_CODES = [
        'USD' => '840',
        'KHR' => '116',
    ];

    /**
     * The school's account is a single Bakong ID with both a KHR and a USD
     * wallet under it (same account number, either currency — confirmed
     * against a real ACLEDA Toanchet "My QR" screen, which shows both under
     * one number and lets the *payer* choose at scan time). So the one
     * template covers both currencies; only the amount and currency tag
     * change per invoice, not the underlying account reference.
     *
     * @param  string  $template  The school's static KHQR string (tag 01 = "11").
     * @param  float  $amount  The exact amount to charge.
     * @param  'USD'|'KHR'  $currency
     *
     * @throws InvalidArgumentException if $template doesn't look like a KHQR string, or $currency isn't supported.
     */
    public static function withAmount(string $template, float $amount, string $currency): string
    {
        if (! isset(self::CURRENCY_CODES[$currency])) {
            throw new InvalidArgumentException("Unsupported currency: {$currency}");
        }

        $withoutCrc = self::stripCrc($template);

        $anchorPos = strpos($withoutCrc, self::COUNTRY_ANCHOR);

        if ($anchorPos === false || strlen($withoutCrc) < 12) {
            throw new InvalidArgumentException('This does not look like a KHQR template.');
        }

        // Tag 01 (Point of Initiation Method): "11" = static/reusable (no
        // amount), "12" = dynamic/one-time (carries a fixed amount) — same
        // EMVCo convention every KHQR reader follows. Tag 00 ("000201") is a
        // fixed 6-byte header, then tag 01's 2-byte id + 2-byte length put
        // its value at a fixed offset of 10.
        $withoutCrc = substr_replace($withoutCrc, '12', 10, 2);

        // Tag 53 (Transaction Currency) is a fixed "53"+"03"+3-digit code —
        // always plain ASCII digits, and always positioned well before the
        // Khmer merchant-name field (58/59/60 come after it), so a direct
        // replace here is just as safe as the amount insertion below.
        $withoutCrc = preg_replace(
            '/5303\d{3}/',
            '5303'.self::CURRENCY_CODES[$currency],
            $withoutCrc,
            1,
        ) ?? $withoutCrc;

        $decimals = $currency === 'KHR' ? 0 : 2;
        $amountField = self::field('54', number_format($amount, $decimals, '.', ''));

        $body = substr($withoutCrc, 0, $anchorPos).$amountField.substr($withoutCrc, $anchorPos).self::CRC_TAG;

        return $body.self::crc16($body);
    }

    private static function field(string $tag, string $value): string
    {
        return $tag.str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;
    }

    private static function stripCrc(string $s): string
    {
        $pos = strrpos($s, self::CRC_TAG);

        return $pos === false ? $s : substr($s, 0, $pos);
    }

    /** CRC-16/CCITT-FALSE over the raw bytes — the checksum algorithm every EMVCo QR (KHQR included) uses. */
    private static function crc16(string $data): string
    {
        $crc = 0xFFFF;

        for ($i = 0, $len = strlen($data); $i < $len; $i++) {
            $crc ^= ord($data[$i]) << 8;

            for ($j = 0; $j < 8; $j++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
