<?php

declare(strict_types=1);

namespace App\Support\Assets;

final class DisposalMethod
{
    public const RECYCLED = 'RECYCLED';

    public const SOLD = 'SOLD';

    public const DONATED = 'DONATED';

    public const DESTROYED = 'DESTROYED';

    public const RETURNED_TO_VENDOR = 'RETURNED_TO_VENDOR';

    public const OTHER = 'OTHER';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::RECYCLED, self::SOLD, self::DONATED, self::DESTROYED, self::RETURNED_TO_VENDOR, self::OTHER];
    }
}
