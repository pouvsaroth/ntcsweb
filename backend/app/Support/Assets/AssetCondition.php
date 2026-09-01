<?php

declare(strict_types=1);

namespace App\Support\Assets;

/** Separate from AssetStatus on purpose — an asset can be Status=IN_USE, Condition=FAIR without being broken. */
final class AssetCondition
{
    public const NEW = 'NEW';

    public const EXCELLENT = 'EXCELLENT';

    public const GOOD = 'GOOD';

    public const FAIR = 'FAIR';

    public const DAMAGED = 'DAMAGED';

    public const BROKEN = 'BROKEN';

    public const UNUSABLE = 'UNUSABLE';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::NEW, self::EXCELLENT, self::GOOD, self::FAIR, self::DAMAGED, self::BROKEN, self::UNUSABLE];
    }
}
