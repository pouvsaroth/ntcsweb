<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Platform\Telegram\Telegram;
use Illuminate\Console\Command;

/** Every minute: link schools' notification groups from "/link CODE" messages (stage 11). */
final class TelegramTickCommand extends Command
{
    protected $signature = 'platform:telegram-tick';

    protected $description = 'Read the Telegram bot updates and link notification groups';

    public function handle(Telegram $telegram): int
    {
        $this->info('Linked: '.$telegram->readUpdates());

        return self::SUCCESS;
    }
}
