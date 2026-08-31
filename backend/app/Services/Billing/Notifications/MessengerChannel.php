<?php

declare(strict_types=1);

namespace App\Services\Billing\Notifications;

use App\Models\Invoice;

/**
 * Deliberately not implemented — this is a documented gap, not a fake
 * integration.
 *
 * Facebook Messenger cannot be messaged like Telegram with an arbitrary
 * phone number. The official Meta Send API requires:
 *   - a registered Meta Business/Developer app, reviewed by Meta for the
 *     `pages_messaging` permission;
 *   - a verified Facebook Page belonging to the school;
 *   - a page access token (env/secure config, never the database — same
 *     rule as the Telegram bot token);
 *   - a Page-Scoped ID (PSID) for each recipient, which only exists once
 *     that person has messaged the Page first (or via a documented
 *     entry point like a "Send to Messenger" plugin/checkbox) — there is
 *     no way to originate a conversation from a phone number or email
 *     alone, and messages sent more than 24h after the user's last
 *     message require an approved message tag.
 *
 * None of that exists in this project today (no Meta app, no Page, no
 * PSID storage). Wiring it up as a real send here would either silently
 * fail against Meta's API or require scraping/automating a personal
 * account, both of which are exactly what this channel must not do.
 *
 * The interface is implemented so InvoiceNotificationService can select
 * 'MESSENGER' like any other channel, get a clear FAILED result explaining
 * why, and this class becomes a real integration later by filling in
 * `send()` once a Meta app/Page/PSID source actually exist — no other part
 * of the billing system needs to change.
 */
final class MessengerChannel implements NotificationChannelContract
{
    public function send(Invoice $invoice, string $recipient): NotificationSendResult
    {
        return NotificationSendResult::failed(
            'Messenger is not implemented: it requires a reviewed Meta Business app, a verified school '.
            'Facebook Page, and a Page-Scoped ID (PSID) for the recipient — none of which this project has '.
            'configured. See MessengerChannel\'s docblock for what real setup this would need.'
        );
    }
}
