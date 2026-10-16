<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Platform\Support\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    /** RFC 6238 Appendix B, the SHA-1 column (8 digits, key "12345678901234567890"). */
    public static function rfcVectors(): array
    {
        return [
            [59, '94287082'],
            [1111111109, '07081804'],
            [1111111111, '14050471'],
            [1234567890, '89005924'],
            [2000000000, '69279037'],
            [20000000000, '65353130'],
        ];
    }

    #[DataProvider('rfcVectors')]
    public function test_it_matches_the_rfc_6238_test_vectors(int $time, string $expected): void
    {
        $this->assertSame($expected, Totp::codeAt('', Totp::step($time), 8, '12345678901234567890'));
    }

    public function test_base32_round_trips(): void
    {
        $bytes = random_bytes(20);

        $this->assertSame($bytes, Totp::base32Decode(Totp::base32Encode($bytes)));
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', Totp::base32Encode('12345678901234567890'));
    }

    public function test_it_accepts_one_step_either_side_and_nothing_further(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_800_000_000;
        $step = Totp::step($now);

        $this->assertSame($step - 1, Totp::verify($secret, Totp::codeAt($secret, $step - 1), $now));
        $this->assertSame($step + 1, Totp::verify($secret, Totp::codeAt($secret, $step + 1), $now));
        $this->assertNull(Totp::verify($secret, Totp::codeAt($secret, $step - 2), $now));
        $this->assertNull(Totp::verify($secret, Totp::codeAt($secret, $step + 2), $now));
    }

    public function test_a_code_at_or_before_the_last_used_step_is_a_replay(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_800_000_000;
        $step = Totp::step($now);

        $this->assertNull(Totp::verify($secret, Totp::codeAt($secret, $step), $now, lastStep: $step));
        $this->assertSame($step + 1, Totp::verify($secret, Totp::codeAt($secret, $step + 1), $now, lastStep: $step));
    }

    public function test_it_rejects_malformed_codes(): void
    {
        $secret = Totp::generateSecret();

        $this->assertNull(Totp::verify($secret, '12345', 1_800_000_000));
        $this->assertNull(Totp::verify($secret, 'abcdef', 1_800_000_000));
    }
}
