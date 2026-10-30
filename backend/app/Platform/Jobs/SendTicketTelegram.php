<?php

declare(strict_types=1);

namespace App\Platform\Jobs;

use App\Platform\Models\SupportSettings;
use App\Platform\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;

/**
 * Tell NewTech's Telegram group about a new support ticket or a customer's
 * reply on one (docs/backoffice/stage-13-help-support.md, 13b). Uses the
 * chat's Telegram settings; nothing when they are not set.
 */
class SendTicketTelegram implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60];

    /** @param  'new'|'reply'  $kind */
    public function __construct(public readonly int $ticketId, public readonly string $kind) {}

    public function handle(): void
    {
        $settings = SupportSettings::current();
        if (blank($settings->telegram_bot_token) || blank($settings->telegram_chat_id)) {
            return;
        }
        $ticket = Ticket::query()->with('tenant')->find($this->ticketId);
        if ($ticket === null) {
            return;
        }
        $last = $ticket->messages()->whereNull('event')->latest('id')->first();
        $files = count($last?->attachments ?? []);

        $head = $this->kind === 'new'
            ? '🎫 New ticket '.$ticket->number().(in_array($ticket->priority, ['high', 'urgent'], true) ? ' · '.strtoupper($ticket->priority) : '')
            : '↩️ Reply on '.$ticket->number();
        $text = $head."\n"
            .($ticket->tenant?->name ?? 'Customer').' · '.$ticket->user_name."\n"
            .$ticket->subject."\n"
            .Str::limit((string) $last?->body, 400)
            .($files ? "\n📎 ".$files.' file'.($files > 1 ? 's' : '') : '')
            ."\n".rtrim((string) config('platform.backoffice_url'), '/').'/support/tickets/'.$ticket->getKey();

        SendChatTelegram::send($settings->telegram_bot_token, $settings->telegram_chat_id, trim($text));
    }
}
