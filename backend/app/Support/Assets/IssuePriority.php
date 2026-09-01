<?php

declare(strict_types=1);

namespace App\Support\Assets;

final class IssuePriority
{
    public const LOW = 'LOW';

    public const MEDIUM = 'MEDIUM';

    public const HIGH = 'HIGH';

    public const CRITICAL = 'CRITICAL';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::LOW, self::MEDIUM, self::HIGH, self::CRITICAL];
    }
}
