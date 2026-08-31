<?php

declare(strict_types=1);

namespace App\Support\Billing;

final class InvoiceStatus
{
    public const DRAFT = 'DRAFT';

    public const ISSUED = 'ISSUED';

    public const PARTIALLY_PAID = 'PARTIALLY_PAID';

    public const PAID = 'PAID';

    public const OVERDUE = 'OVERDUE';

    public const CANCELLED = 'CANCELLED';

    public const VOID = 'VOID';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::DRAFT, self::ISSUED, self::PARTIALLY_PAID, self::PAID,
            self::OVERDUE, self::CANCELLED, self::VOID,
        ];
    }

    /** Terminal states — a payment can never be recorded against one of these. */
    public static function isClosed(string $status): bool
    {
        return in_array($status, [self::CANCELLED, self::VOID], true);
    }
}
