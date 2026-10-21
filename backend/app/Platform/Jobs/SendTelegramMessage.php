<?php

declare(strict_types=1);

namespace App\Platform\Jobs;

use App\Platform\Telegram\Telegram;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** One text to one Telegram chat, in the background; a failure never reaches the user. */
class SendTelegramMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public function __construct(public readonly string $chatId, public readonly string $text) {}

    public function handle(Telegram $telegram): void
    {
        $result = $telegram->send($this->chatId, $this->text);
        if (! $result['ok'] && str_contains($result['message'], 'Too Many Requests')) {
            $this->release(30);
        }
    }
}
