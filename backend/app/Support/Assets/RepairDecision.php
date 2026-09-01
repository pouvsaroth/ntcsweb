<?php

declare(strict_types=1);

namespace App\Support\Assets;

/** When an asset is broken, what an authorized user decided to do about it — see spec section 23. */
final class RepairDecision
{
    public const REPAIR = 'REPAIR';

    public const REPLACE = 'REPLACE';

    public const RETIRE = 'RETIRE';

    public const DISPOSE = 'DISPOSE';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::REPAIR, self::REPLACE, self::RETIRE, self::DISPOSE];
    }
}
