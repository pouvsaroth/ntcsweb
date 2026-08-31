<?php

declare(strict_types=1);

namespace App\Services\Billing\Notifications;

use App\Models\Invoice;
use App\Services\Billing\InvoicePdfService;
use Illuminate\Support\Facades\Http;

/**
 * `$recipient` is a Telegram chat ID (obtained by the student/parent
 * messaging the school's bot at least once — Telegram has no way to push a
 * message to an arbitrary phone number, only to a chat ID your bot has
 * already been given). The bot token is env-only (services.telegram.
 * bot_token / TELEGRAM_BOT_TOKEN) — never stored in the database.
 *
 * Uses Telegram's Bot API directly via Laravel's stock Http facade
 * (Guzzle, already a framework dependency) — no new package.
 */
final class TelegramChannel implements NotificationChannelContract
{
    public function send(Invoice $invoice, string $recipient): NotificationSendResult
    {
        $token = config('services.telegram.bot_token');

        if (! is_string($token) || $token === '') {
            return NotificationSendResult::failed('Telegram is not configured (services.telegram.bot_token / TELEGRAM_BOT_TOKEN is empty).');
        }

        $caption = $this->caption($invoice);

        try {
            $pdf = app(InvoicePdfService::class);

            $response = Http::asMultipart()->post(
                "https://api.telegram.org/bot{$token}/sendDocument",
                [
                    ['name' => 'chat_id', 'contents' => $recipient],
                    ['name' => 'caption', 'contents' => $caption],
                    ['name' => 'document', 'contents' => $pdf->render($invoice), 'filename' => $pdf->filename($invoice)],
                ],
            );

            if ($response->failed()) {
                return NotificationSendResult::failed('Telegram API error: '.($response->json('description') ?? $response->status()));
            }

            return NotificationSendResult::sent((string) $response->json('result.message_id'));
        } catch (\Throwable $e) {
            report($e);

            return NotificationSendResult::failed($e->getMessage());
        }
    }

    private function caption(Invoice $invoice): string
    {
        $tenant = $invoice->tenant;

        return trim(
            ($tenant?->name ?? config('app.name'))."\n\n".
            "Dear {$invoice->student->fullName()},\n\n".
            "Your invoice has been issued.\n\n".
            "Invoice: {$invoice->invoice_number}\n".
            'Total: $'.number_format((float) $invoice->total, 2)."\n".
            'Paid: $'.number_format((float) $invoice->paid_amount, 2)."\n".
            'Balance: $'.number_format((float) $invoice->balance, 2)."\n\n".
            'Please find your invoice attached.'
        );
    }
}
