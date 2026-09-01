<?php

declare(strict_types=1);

namespace App\Support\Assets;

/**
 * The asset lifecycle — a catalog, not free text (see Asset's own docblock).
 * `TRANSITIONS` is the adjacency map `AssetStatusTransitionService` enforces
 * on every status change; nothing may jump to a status not listed for its
 * current one.
 *
 * There is no persisted `FOUND` status: "found" is instantaneous, moving a
 * LOST/MISSING asset straight into UNDER_INSPECTION (matching the spec's own
 * `LOST -> FOUND -> UNDER_INSPECTION` diagram, where FOUND is the transient
 * moment, not a resting state) — see AssetLifecycleService::markFound().
 */
final class AssetStatus
{
    public const IN_STOCK = 'IN_STOCK';

    public const ASSIGNED = 'ASSIGNED';

    public const IN_USE = 'IN_USE';

    public const ISSUE_REPORTED = 'ISSUE_REPORTED';

    public const UNDER_INSPECTION = 'UNDER_INSPECTION';

    public const BROKEN = 'BROKEN';

    public const UNDER_REPAIR = 'UNDER_REPAIR';

    public const REPAIR_COMPLETED = 'REPAIR_COMPLETED';

    public const READY_FOR_USE = 'READY_FOR_USE';

    public const STOPPED_USE = 'STOPPED_USE';

    public const RETIRED = 'RETIRED';

    public const DISPOSED = 'DISPOSED';

    public const LOST = 'LOST';

    public const MISSING = 'MISSING';

    /**
     * current status => list of statuses it may move to next. RETIRED only
     * ever leads to DISPOSED (no "unless an authorized restoration process
     * exists" feature this phase); DISPOSED has no way out at all.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::IN_STOCK => [self::ASSIGNED, self::UNDER_INSPECTION, self::STOPPED_USE, self::RETIRED, self::LOST, self::MISSING],
        self::ASSIGNED => [self::IN_USE, self::IN_STOCK, self::ISSUE_REPORTED, self::LOST, self::MISSING],
        self::IN_USE => [self::ISSUE_REPORTED, self::IN_STOCK, self::ASSIGNED, self::STOPPED_USE, self::LOST, self::MISSING],
        self::ISSUE_REPORTED => [self::UNDER_INSPECTION, self::IN_USE, self::UNDER_REPAIR, self::BROKEN],
        self::UNDER_INSPECTION => [self::UNDER_REPAIR, self::BROKEN, self::IN_USE, self::IN_STOCK, self::RETIRED],
        self::BROKEN => [self::UNDER_REPAIR, self::RETIRED, self::DISPOSED],
        self::UNDER_REPAIR => [self::REPAIR_COMPLETED, self::BROKEN, self::RETIRED],
        self::REPAIR_COMPLETED => [self::READY_FOR_USE, self::IN_USE, self::IN_STOCK],
        self::READY_FOR_USE => [self::IN_USE, self::IN_STOCK],
        self::STOPPED_USE => [self::IN_STOCK, self::RETIRED],
        self::RETIRED => [self::DISPOSED],
        self::DISPOSED => [],
        self::LOST => [self::UNDER_INSPECTION],
        self::MISSING => [self::UNDER_INSPECTION, self::LOST, self::IN_STOCK, self::IN_USE],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    public static function isClosed(string $status): bool
    {
        return in_array($status, [self::RETIRED, self::DISPOSED], true);
    }
}
