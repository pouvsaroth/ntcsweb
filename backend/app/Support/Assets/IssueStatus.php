<?php

declare(strict_types=1);

namespace App\Support\Assets;

final class IssueStatus
{
    public const OPEN = 'OPEN';

    public const ACKNOWLEDGED = 'ACKNOWLEDGED';

    public const UNDER_INSPECTION = 'UNDER_INSPECTION';

    public const WAITING_FOR_PART = 'WAITING_FOR_PART';

    public const SENT_TO_REPAIR = 'SENT_TO_REPAIR';

    public const REPAIRED = 'REPAIRED';

    public const RESOLVED = 'RESOLVED';

    public const CLOSED = 'CLOSED';

    public const CANCELLED = 'CANCELLED';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::OPEN, self::ACKNOWLEDGED, self::UNDER_INSPECTION, self::WAITING_FOR_PART,
            self::SENT_TO_REPAIR, self::REPAIRED, self::RESOLVED, self::CLOSED, self::CANCELLED,
        ];
    }

    public static function isClosed(string $status): bool
    {
        return in_array($status, [self::RESOLVED, self::CLOSED, self::CANCELLED], true);
    }
}
