<?php

declare(strict_types=1);

namespace App\Support\Billing;

/**
 * A catalog, not a rigid enum — the same convention as Permissions/
 * AuditAction. Adding a new method a school actually uses is one new
 * constant here, not a schema change (the column is a plain string).
 */
final class PaymentMethod
{
    public const CASH = 'CASH';

    public const BANK_TRANSFER = 'BANK_TRANSFER';

    public const ABA = 'ABA';

    public const ACLEDA = 'ACLEDA';

    public const CARD = 'CARD';

    public const OTHER = 'OTHER';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::CASH, self::BANK_TRANSFER, self::ABA, self::ACLEDA, self::CARD, self::OTHER];
    }
}
