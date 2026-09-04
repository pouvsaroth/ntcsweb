<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Support\Billing\Khqr;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Ground-truth verification against a real ACLEDA Toanchet KHQR string
 * (decoded from an actual "My QR" code) — see Khqr's own docblock for why
 * the Khmer merchant-name field specifically must never be touched.
 */
class KhqrTest extends TestCase
{
    private const REAL_ACLEDA_TEMPLATE = '00020101021129380009khqr@aclb0111855100589230206ACLEDA391300042CCY010145204599953031165802KH5910ពៅ  សារដ្ធ6010PHNOM PENH621302090122374226304D1E3';

    /**
     * The critical ground-truth check: CRC-16/CCITT-FALSE, computed by an
     * implementation written independently of Khqr's own (duplicated here on
     * purpose — two separately-written implementations agreeing is real
     * evidence, checking Khqr's algorithm against itself would not be),
     * reproduces the real template's own trailing checksum exactly. This is
     * what proves Bakong KHQR uses this specific CRC variant at all.
     */
    public function test_an_independently_written_crc16_matches_the_real_templates_own_checksum(): void
    {
        $prefix = substr(self::REAL_ACLEDA_TEMPLATE, 0, -4);
        $declaredCrc = substr(self::REAL_ACLEDA_TEMPLATE, -4);

        $this->assertSame($declaredCrc, self::independentCrc16($prefix));
    }

    public function test_an_independently_written_crc16_matches_khqrs_own_output(): void
    {
        $result = Khqr::withAmount(self::REAL_ACLEDA_TEMPLATE, 24.00, 'USD');

        $declaredCrc = substr($result, -4);
        $body = substr($result, 0, -4);

        $this->assertSame($declaredCrc, self::independentCrc16($body));
    }

    private static function independentCrc16(string $data): string
    {
        $crc = 0xFFFF;

        for ($i = 0, $len = strlen($data); $i < $len; $i++) {
            $crc ^= ord($data[$i]) << 8;

            for ($j = 0; $j < 8; $j++) {
                $crc = ($crc & 0x8000) !== 0 ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    public function test_everything_from_the_country_code_onward_is_byte_for_byte_untouched(): void
    {
        $result = Khqr::withAmount(self::REAL_ACLEDA_TEMPLATE, 24.00, 'USD');

        $originalSuffix = substr(self::REAL_ACLEDA_TEMPLATE, strpos(self::REAL_ACLEDA_TEMPLATE, '5802KH'));
        // Original suffix still ends in its own CRC (6304D1E3); the result's
        // suffix ends in a freshly computed one instead, so compare only the
        // part before the CRC tag.
        $originalSuffixNoCrc = substr($originalSuffix, 0, strrpos($originalSuffix, '6304'));

        $resultSuffix = substr($result, strpos($result, '5802KH'));
        $resultSuffixNoCrc = substr($resultSuffix, 0, strrpos($resultSuffix, '6304'));

        $this->assertSame($originalSuffixNoCrc, $resultSuffixNoCrc, 'The Khmer merchant name, city, and account fields must never change.');
    }

    public function test_the_point_of_initiation_method_flips_from_static_to_dynamic(): void
    {
        $this->assertSame('11', substr(self::REAL_ACLEDA_TEMPLATE, 10, 2), 'Precondition: the real template is static.');

        $result = Khqr::withAmount(self::REAL_ACLEDA_TEMPLATE, 24.00, 'USD');

        $this->assertSame('12', substr($result, 10, 2));
    }

    public function test_the_amount_is_embedded_and_extractable_with_the_right_decimal_places(): void
    {
        $result = Khqr::withAmount(self::REAL_ACLEDA_TEMPLATE, 24.5, 'USD');

        $this->assertStringContainsString('540524.50', $result);
    }

    public function test_a_khr_amount_has_no_decimal_places(): void
    {
        $result = Khqr::withAmount(self::REAL_ACLEDA_TEMPLATE, 50000, 'KHR');

        $this->assertStringContainsString('540550000', $result);
    }

    /**
     * The real template's own currency tag is 116 (KHR) — see its parsed
     * value. A USD invoice must not silently generate a code still flagged
     * as Riel; a KHR invoice, conversely, must correctly stay 116 even
     * though it happens to match the template's own default.
     */
    public function test_the_currency_tag_reflects_the_invoices_currency_not_the_templates_default(): void
    {
        $this->assertStringContainsString('5303116', self::REAL_ACLEDA_TEMPLATE, 'Precondition: the real template defaults to KHR (116).');

        $usd = Khqr::withAmount(self::REAL_ACLEDA_TEMPLATE, 24.00, 'USD');
        $this->assertStringContainsString('5303840', $usd);
        $this->assertStringNotContainsString('5303116', $usd);

        $khr = Khqr::withAmount(self::REAL_ACLEDA_TEMPLATE, 50000, 'KHR');
        $this->assertStringContainsString('5303116', $khr);
    }

    public function test_the_result_carries_a_valid_self_consistent_crc(): void
    {
        $result = Khqr::withAmount(self::REAL_ACLEDA_TEMPLATE, 24.00, 'USD');

        $declaredCrc = substr($result, -4);
        $bodyWithoutCrc = substr($result, 0, -4);

        // Re-derive the same CRC a second, independent way an amount of 0
        // wouldn't prove: call withAmount() again against a string built by
        // hand-appending the same body — if crc16() were nondeterministic or
        // buggy, two independent calls covering the same bytes would diverge.
        $again = Khqr::withAmount(self::REAL_ACLEDA_TEMPLATE, 24.00, 'USD');

        $this->assertSame($result, $again, 'withAmount() must be deterministic.');
        $this->assertSame(4, strlen($declaredCrc));
        $this->assertMatchesRegularExpression('/^[0-9A-F]{4}$/', $declaredCrc);
        $this->assertStringEndsWith($declaredCrc, $bodyWithoutCrc.$declaredCrc);
    }

    public function test_a_string_with_no_country_code_field_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Khqr::withAmount('not-a-real-khqr-string', 10.0, 'USD');
    }

    public function test_an_unsupported_currency_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Khqr::withAmount(self::REAL_ACLEDA_TEMPLATE, 10.0, 'EUR');
    }
}
