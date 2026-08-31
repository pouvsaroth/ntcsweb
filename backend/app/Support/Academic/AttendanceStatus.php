<?php

declare(strict_types=1);

namespace App\Support\Academic;

/**
 * A catalog, not a rigid enum — same convention as PaymentMethod/ProductType.
 */
final class AttendanceStatus
{
    public const PRESENT = 'PRESENT';

    public const ABSENT = 'ABSENT';

    public const LATE = 'LATE';

    public const EXCUSED = 'EXCUSED';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::PRESENT, self::ABSENT, self::LATE, self::EXCUSED];
    }
}
