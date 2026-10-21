<?php

declare(strict_types=1);

namespace App\Platform\Telegram;

use App\Models\NotificationGroup;
use App\Models\User;
use App\Platform\Jobs\SendTelegramMessage;
use Illuminate\Support\Facades\Cache;

/**
 * A bell notification also goes to the Telegram groups its recipient belongs
 * to (docs/backoffice/stage-11-telegram-notifications.md). One event sent to
 * several members of the same group arrives there once.
 */
final class NotificationTelegram
{
    public function __construct(private readonly Telegram $telegram) {}

    /** @param  array<string, mixed>  $data */
    public function forNotification(User $recipient, string $type, array $data, ?string $link): void
    {
        if ($recipient->tenant_id === null || $this->telegram->token() === null) {
            return;
        }
        // Plain table reads: going through the User model here resets the
        // request's current school (and row-level security) for later writes.
        $groups = \Illuminate\Support\Facades\DB::connection(config('tenancy.database.central_connection'))
            ->table('notification_groups as g')
            ->join('notification_group_user as m', 'm.notification_group_id', '=', 'g.id')
            ->where('g.tenant_id', $recipient->tenant_id)
            ->where('m.user_id', $recipient->getKey())
            ->whereNotNull('g.telegram_chat_id')
            ->get(['g.id', 'g.telegram_chat_id']);
        if ($groups->isEmpty()) {
            return;
        }

        $text = $this->render($recipient->tenant_id, $type, $data, $link);
        $event = md5($type.'|'.json_encode($data).'|'.$link);
        // A job run at once (sync queue) ends with tenancy reset; the request
        // goes on writing for this school, so its school is put back after.
        $context = app(\App\Support\Tenancy\TenantContext::class);
        $school = $context->get();
        foreach ($groups as $group) {
            // Same event, same Telegram group: once (several approvers in one group).
            if (Cache::add("tg-notify:{$group->id}:{$event}", 1, now()->addMinutes(10))) {
                SendTelegramMessage::dispatch($group->telegram_chat_id, $text)->afterCommit();
            }
        }
        if ($school !== null && $context->id() !== $school->getKey()) {
            $context->set($school);
        }
    }

    /** @param  array<string, mixed>  $data */
    public function render(int $tenantId, string $type, array $data, ?string $link): string
    {
        // A plain read: loading the Tenant model here would reset the request's
        // current school (and row-level security) for the writes after it.
        $tenant = \Illuminate\Support\Facades\DB::connection(config('tenancy.database.central_connection'))
            ->table('tenants')->where('id', $tenantId)->first(['name', 'locale']);
        $locale = $tenant?->locale === 'km' ? 'km' : 'en';
        $replace = array_filter($data, fn ($v) => is_scalar($v) || $v === null);
        $key = "notification_types.{$type}";
        $line = trans($key, array_map(fn ($v) => (string) $v, $replace), $locale);
        if ($line === $key) {
            $line = ucfirst(str_replace('_', ' ', $type)); // a type with no text yet
        }

        $url = $link ? rtrim((string) config('platform.console_url'), '/').'/'.ltrim($link, '/') : null;

        return '🔔 '.($tenant?->name ? $tenant->name."\n" : '').$line.($url ? "\n".$url : '');
    }
}
