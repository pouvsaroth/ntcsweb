<?php

declare(strict_types=1);

namespace App\Support\Accounting;

/** See ExpenseService for the transitions this drives: create -> PENDING_APPROVAL -> APPROVED -> PAID, or REJECTED/CANCELLED along the way. */
final class ExpenseStatus
{
    public const DRAFT = 'DRAFT';

    public const PENDING_APPROVAL = 'PENDING_APPROVAL';

    public const APPROVED = 'APPROVED';

    public const PAID = 'PAID';

    public const REJECTED = 'REJECTED';

    public const CANCELLED = 'CANCELLED';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::DRAFT, self::PENDING_APPROVAL, self::APPROVED, self::PAID, self::REJECTED, self::CANCELLED];
    }

    public static function isClosed(string $status): bool
    {
        return in_array($status, [self::PAID, self::REJECTED, self::CANCELLED], true);
    }
}
