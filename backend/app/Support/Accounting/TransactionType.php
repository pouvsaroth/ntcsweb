<?php

declare(strict_types=1);

namespace App\Support\Accounting;

/**
 * What kind of event a FinancialTransaction records. REVENUE/EXPENSE reports
 * filter on this (not on account type alone) so a TRANSFER between two Asset
 * accounts is never mistaken for income or spending — see
 * AccountingReportService.
 */
final class TransactionType
{
    public const INCOME = 'INCOME';

    public const EXPENSE = 'EXPENSE';

    public const TRANSFER = 'TRANSFER';

    public const REFUND = 'REFUND';

    public const ADJUSTMENT = 'ADJUSTMENT';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::INCOME, self::EXPENSE, self::TRANSFER, self::REFUND, self::ADJUSTMENT];
    }
}
