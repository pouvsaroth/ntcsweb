<?php

declare(strict_types=1);

namespace App\Platform\Telegram;

use App\Models\NotificationGroup;
use App\Models\Tenant;
use App\Platform\Models\SupportSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * NewTech's one Telegram bot (token in back office → Support → Settings),
 * used for support chat alerts and for schools' notification groups
 * (docs/backoffice/stage-11-telegram-notifications.md).
 */
final class Telegram
{
    public function token(): ?string
    {
        $token = SupportSettings::current()->telegram_bot_token;

        return filled($token) ? $token : null;
    }

    /** @return array{ok: bool, message: string} */
    public function send(string $chatId, string $text): array
    {
        $token = $this->token();
        if ($token === null) {
            return ['ok' => false, 'message' => 'No Telegram bot token is set.'];
        }
        $response = Http::timeout(10)->asJson()->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => Str::limit($text, 4000),
            'disable_web_page_preview' => true,
        ]);

        return ['ok' => $response->successful() && $response->json('ok') === true, 'message' => (string) ($response->json('description') ?? ('HTTP '.$response->status()))];
    }

    /**
     * Read the bot's new messages and link groups: "/link CODE" (or
     * "/link@bot CODE") sent in a Telegram group links the notification
     * group whose code it is. Returns how many groups were linked.
     */
    public function readUpdates(): int
    {
        $token = $this->token();
        if ($token === null) {
            return 0;
        }
        $settings = SupportSettings::current();
        $response = Http::timeout(15)->get("https://api.telegram.org/bot{$token}/getUpdates", array_filter([
            'offset' => $settings->telegram_update_offset,
            'timeout' => 0,
            'allowed_updates' => json_encode(['message']),
        ], fn ($v) => $v !== null));
        if (! $response->successful() || $response->json('ok') !== true) {
            return 0;
        }

        $linked = 0;
        $last = null;
        foreach ((array) $response->json('result', []) as $update) {
            $last = (int) ($update['update_id'] ?? 0);
            $message = $update['message'] ?? null;
            $text = trim((string) ($message['text'] ?? ''));
            if (! $message || ! preg_match('/^\/link(?:@\w+)?\s+([A-Z0-9]{4,12})$/i', $text, $m)) {
                continue;
            }
            $group = NotificationGroup::query()->where('link_code', strtoupper($m[1]))->where('link_code_expires_at', '>', now())->first();
            $chat = $message['chat'] ?? [];
            if ($group === null || ! in_array($chat['type'] ?? '', ['group', 'supergroup', 'channel'], true)) {
                continue;
            }
            $group->forceFill([
                'telegram_chat_id' => (string) $chat['id'],
                'telegram_title' => $chat['title'] ?? null,
                'linked_at' => now(),
                'link_code' => null,
                'link_code_expires_at' => null,
            ])->save();
            $school = Tenant::query()->whereKey($group->tenant_id)->value('name');
            $this->send((string) $chat['id'], "✅ Linked: {$school} · {$group->name}\nNotifications for this group will arrive here.");
            $linked++;
        }
        if ($last !== null) {
            $settings->forceFill(['telegram_update_offset' => $last + 1])->save();
        }

        return $linked;
    }
}
