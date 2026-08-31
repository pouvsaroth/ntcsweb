<?php

declare(strict_types=1);

namespace App\Support\Billing;

/**
 * Named *ChannelName*, not *Channel* — App\Services\Billing\Notifications
 * already has a Channel *interface*; this is just the catalog of valid
 * values for the `channel` column/API parameter.
 */
final class NotificationChannelName
{
    public const EMAIL = 'EMAIL';

    public const TELEGRAM = 'TELEGRAM';

    public const MESSENGER = 'MESSENGER';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::EMAIL, self::TELEGRAM, self::MESSENGER];
    }
}
