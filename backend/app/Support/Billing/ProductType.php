<?php

declare(strict_types=1);

namespace App\Support\Billing;

/**
 * For filtering/reporting only — nothing in the billing engine branches on
 * this. A school adding a genuinely new kind of sellable thing can use
 * OTHER immediately; a new constant here is a convenience, never a
 * requirement (see Product's own docblock).
 */
final class ProductType
{
    public const COURSE_FEE = 'COURSE_FEE';

    public const BOOK = 'BOOK';

    public const T_SHIRT = 'T_SHIRT';

    public const UNIFORM = 'UNIFORM';

    public const CERTIFICATE = 'CERTIFICATE';

    public const OTHER = 'OTHER';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::COURSE_FEE, self::BOOK, self::T_SHIRT, self::UNIFORM, self::CERTIFICATE, self::OTHER];
    }
}
