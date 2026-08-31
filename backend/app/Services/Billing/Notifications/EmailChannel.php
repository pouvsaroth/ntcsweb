<?php

declare(strict_types=1);

namespace App\Services\Billing\Notifications;

use App\Mail\InvoiceMail;
use App\Models\Invoice;
use Illuminate\Support\Facades\Mail;

/**
 * `$recipient` is an email address. Uses the project's existing mail
 * configuration (MAIL_MAILER etc. — `log` in development, so nothing is
 * actually delivered until a real mailer is configured, exactly like every
 * other notification in this app).
 */
final class EmailChannel implements NotificationChannelContract
{
    public function send(Invoice $invoice, string $recipient): NotificationSendResult
    {
        try {
            Mail::to($recipient)->send(new InvoiceMail($invoice));

            return NotificationSendResult::sent();
        } catch (\Throwable $e) {
            report($e);

            return NotificationSendResult::failed($e->getMessage());
        }
    }
}
