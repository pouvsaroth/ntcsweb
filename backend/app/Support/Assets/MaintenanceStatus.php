<?php

declare(strict_types=1);

namespace App\Support\Assets;

/**
 * OVERDUE is never stored — it's derived on read (SCHEDULED with a
 * scheduled_date in the past), matching the spec's own instruction not to
 * add a background job just to flip a status.
 */
final class MaintenanceStatus
{
    public const SCHEDULED = 'SCHEDULED';

    public const IN_PROGRESS = 'IN_PROGRESS';

    public const COMPLETED = 'COMPLETED';

    public const CANCELLED = 'CANCELLED';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::SCHEDULED, self::IN_PROGRESS, self::COMPLETED, self::CANCELLED];
    }
}
