<?php

declare(strict_types=1);

namespace App\Support\Academic;

/**
 * The school's exam mention scale (0–100 score), as set by the school:
 * above 95 Excellent (ល្អប្រសើរ), above 90 Very good (ល្អណាស់), 85–90 Good
 * (ល្អ), below 85 Fail (ធ្លាក់). Returned as a key — the frontend
 * translates it.
 */
final class ExamMention
{
    public const EXCELLENT = 'excellent';

    public const VERY_GOOD = 'very_good';

    public const GOOD = 'good';

    public const FAIL = 'fail';

    public static function for(float $score): string
    {
        return match (true) {
            $score > 95 => self::EXCELLENT,
            $score > 90 => self::VERY_GOOD,
            $score >= 85 => self::GOOD,
            default => self::FAIL,
        };
    }
}
