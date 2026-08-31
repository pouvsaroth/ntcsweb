<?php

declare(strict_types=1);

namespace App\Support\Billing;

final class PaymentStatus
{
    public const COMPLETED = 'COMPLETED';

    public const CANCELLED = 'CANCELLED';

    public const REFUNDED = 'REFUNDED';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::COMPLETED, self::CANCELLED, self::REFUNDED];
    }
}
