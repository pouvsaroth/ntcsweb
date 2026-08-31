<?php

declare(strict_types=1);

namespace App\Services\Billing\Notifications;

use App\Support\Billing\NotificationStatus;

/**
 * What a channel implementation reports back — deliberately not an
 * exception: a failed send is an expected, first-class outcome here (it
 * gets logged to notification_logs, not thrown), never a reason to roll
 * back the invoice/payment that triggered it.
 */
final readonly class NotificationSendResult
{
    private function __construct(
        public string $status,
        public ?string $providerMessageId,
        public ?string $errorMessage,
    ) {}

    public static function sent(?string $providerMessageId = null): self
    {
        return new self(NotificationStatus::SENT, $providerMessageId, null);
    }

    public static function failed(string $errorMessage): self
    {
        return new self(NotificationStatus::FAILED, null, $errorMessage);
    }
}
