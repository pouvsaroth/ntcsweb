<?php

declare(strict_types=1);

namespace App\Support\Assets;

final class RepairStatus
{
    public const PENDING = 'PENDING';

    public const SENT_TO_SHOP = 'SENT_TO_SHOP';

    public const RECEIVED_BY_SHOP = 'RECEIVED_BY_SHOP';

    public const UNDER_REPAIR = 'UNDER_REPAIR';

    public const WAITING_FOR_PARTS = 'WAITING_FOR_PARTS';

    public const REPAIR_COMPLETED = 'REPAIR_COMPLETED';

    public const RETURNED = 'RETURNED';

    public const CANCELLED = 'CANCELLED';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::PENDING, self::SENT_TO_SHOP, self::RECEIVED_BY_SHOP, self::UNDER_REPAIR,
            self::WAITING_FOR_PARTS, self::REPAIR_COMPLETED, self::RETURNED, self::CANCELLED,
        ];
    }

    public static function isClosed(string $status): bool
    {
        return in_array($status, [self::RETURNED, self::CANCELLED], true);
    }
}
