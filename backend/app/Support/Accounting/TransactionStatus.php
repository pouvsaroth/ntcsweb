<?php

declare(strict_types=1);

namespace App\Support\Accounting;

/** A posted transaction is never edited or deleted — REVERSED marks it superseded by a reversal row. See FinancialTransaction's docblock. */
final class TransactionStatus
{
    public const POSTED = 'POSTED';

    public const REVERSED = 'REVERSED';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::POSTED, self::REVERSED];
    }
}
