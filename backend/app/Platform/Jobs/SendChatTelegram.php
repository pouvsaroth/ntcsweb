<?php

declare(strict_types=1);

namespace App\Platform\Jobs;

use App\Platform\Models\ChatMessage;
use App\Platform\Models\SupportSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Tell NewTech's Telegram group a customer asked something in the support
 * chat (docs/backoffice/stage-10-support-chat.md). Nothing when Telegram is
 * not set up; a failure is reported, never shown to the customer.
 */
class SendChatTelegram implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public function __construct(public readonly int $messageId) {}

    public function handle(): void
    {
        $settings = SupportSettings::current();
        if (blank($settings->telegram_bot_token) || blank($settings->telegram_chat_id)) {
            return;
        }
        $message = ChatMessage::query()->with('chat.tenant')->find($this->messageId);
        if ($message === null || $message->chat === null) {
            return;
        }

        $chat = $message->chat;
        $files = count($message->attachments ?? []);
        $text = '💬 '.($chat->tenant?->name ?? 'Customer').' · '.$chat->user_name."\n"
            .(Str::limit((string) $message->body, 500) ?: '')
            .($files ? "\n📎 ".$files.' file'.($files > 1 ? 's' : '') : '')
            ."\n".rtrim((string) config('platform.backoffice_url'), '/').'/support/chats/'.$chat->getKey();

        self::send($settings->telegram_bot_token, $settings->telegram_chat_id, trim($text));
    }

    /** @return array{ok: bool, message: string} */
    public static function send(string $token, string $chatId, string $text): array
    {
        $response = Http::timeout(10)->asJson()->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => true,
        ]);

        return ['ok' => $response->successful() && $response->json('ok') === true, 'message' => (string) ($response->json('description') ?? ('HTTP '.$response->status()))];
    }
}
