<?php

declare(strict_types=1);

namespace App\Services\Billing\Notifications;

use App\Models\Invoice;

/**
 * One implementation per delivery channel (Telegram, Email, Messenger, ...).
 * InvoiceNotificationService picks one by name and never contains
 * channel-specific code itself — see that class's docblock.
 */
interface NotificationChannelContract
{
    public function send(Invoice $invoice, string $recipient): NotificationSendResult;
}
