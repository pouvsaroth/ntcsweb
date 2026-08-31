<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\Billing\Notifications\EmailChannel;
use App\Services\Billing\Notifications\MessengerChannel;
use App\Services\Billing\Notifications\NotificationChannelContract;
use App\Services\Billing\Notifications\TelegramChannel;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Billing\NotificationChannelName;
use App\Support\Billing\NotificationStatus;
use InvalidArgumentException;

/**
 * The single entry point for sending an invoice out — see the class-level
 * architecture note: nothing in a controller ever talks to Telegram/Mail
 * directly. Resend creates a brand-new NotificationLog row rather than
 * touching a previous one (see that model's docblock), so the full history
 * of every attempt, including past failures, is always visible.
 *
 * A failed send is recorded and reported (AuditAction::INVOICE_SEND_FAILED)
 * but never throws past this method and never touches the invoice/payment
 * it was sent for — see class rule in AuditLogger's billing section.
 */
final class InvoiceNotificationService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function send(Invoice $invoice, string $recipient, string $channel, ?User $actor = null, string $type = 'invoice_issued'): NotificationLog
    {
        $log = NotificationLog::query()->create([
            'invoice_id' => $invoice->id,
            'student_id' => $invoice->student_id,
            'channel' => $channel,
            'recipient' => $recipient,
            'type' => $type,
            'status' => NotificationStatus::PENDING,
            'sent_by' => $actor?->getKey(),
        ]);

        $result = $this->channel($channel)->send($invoice, $recipient);

        $log->update([
            'status' => $result->status,
            'message' => $result->status === NotificationStatus::SENT ? 'Sent successfully.' : null,
            'provider_message_id' => $result->providerMessageId,
            'error_message' => $result->errorMessage,
            'sent_at' => $result->status === NotificationStatus::SENT ? now() : null,
        ]);

        $this->audit->log(
            $result->status === NotificationStatus::SENT ? AuditAction::INVOICE_SENT : AuditAction::INVOICE_SEND_FAILED,
            'Invoices',
            $invoice,
            new: ['channel' => $channel, 'recipient' => $recipient, 'status' => $result->status],
            description: $result->status === NotificationStatus::SENT
                ? "Sent invoice {$invoice->invoice_number} via {$channel} to {$recipient}"
                : "Failed to send invoice {$invoice->invoice_number} via {$channel}: {$result->errorMessage}",
            actor: $actor,
        );

        return $log->fresh();
    }

    private function channel(string $name): NotificationChannelContract
    {
        return match ($name) {
            NotificationChannelName::EMAIL => app(EmailChannel::class),
            NotificationChannelName::TELEGRAM => app(TelegramChannel::class),
            NotificationChannelName::MESSENGER => app(MessengerChannel::class),
            default => throw new InvalidArgumentException("Unknown notification channel [{$name}]."),
        };
    }
}
