<?php

declare(strict_types=1);

namespace App\Support\Accounting;

/**
 * The five standard accounting classifications. `normalBalance()` says
 * whether a plain (debits - credits) or (credits - debits) reading gives
 * this type's natural positive balance — see Account::normalBalance().
 */
final class AccountType
{
    public const ASSET = 'ASSET';

    public const LIABILITY = 'LIABILITY';

    public const EQUITY = 'EQUITY';

    public const REVENUE = 'REVENUE';

    public const EXPENSE = 'EXPENSE';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::ASSET, self::LIABILITY, self::EQUITY, self::REVENUE, self::EXPENSE];
    }

    /** Debit-normal types read positive as (debits - credits); credit-normal types the other way round. */
    public static function isDebitNormal(string $type): bool
    {
        return in_array($type, [self::ASSET, self::EXPENSE], true);
    }
}
