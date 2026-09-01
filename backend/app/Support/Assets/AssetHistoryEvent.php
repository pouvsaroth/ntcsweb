<?php

declare(strict_types=1);

namespace App\Support\Assets;

/** The business-lifecycle event types AssetHistoryRecorder writes — see that class and AssetHistory's own docblock. */
final class AssetHistoryEvent
{
    public const CREATED = 'CREATED';

    public const UPDATED = 'UPDATED';

    public const ASSIGNED = 'ASSIGNED';

    public const RETURNED = 'RETURNED';

    public const TRANSFERRED = 'TRANSFERRED';

    public const ISSUE_REPORTED = 'ISSUE_REPORTED';

    public const STATUS_CHANGED = 'STATUS_CHANGED';

    public const CONDITION_CHANGED = 'CONDITION_CHANGED';

    public const SENT_TO_REPAIR = 'SENT_TO_REPAIR';

    public const REPAIR_COMPLETED = 'REPAIR_COMPLETED';

    public const MAINTENANCE_SCHEDULED = 'MAINTENANCE_SCHEDULED';

    public const MAINTENANCE_COMPLETED = 'MAINTENANCE_COMPLETED';

    public const RETIRED = 'RETIRED';

    public const DISPOSED = 'DISPOSED';

    public const LOST = 'LOST';

    public const FOUND = 'FOUND';
}
