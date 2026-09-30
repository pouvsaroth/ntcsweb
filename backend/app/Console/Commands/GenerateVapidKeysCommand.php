<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * One-time setup for phone notifications (Web Push): prints a VAPID key
 * pair to paste into .env. Generate once per environment and keep it —
 * changing the keys invalidates every device already subscribed.
 */
final class GenerateVapidKeysCommand extends Command
{
    protected $signature = 'webpush:vapid';

    protected $description = 'Generate a VAPID key pair for Web Push phone notifications';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->line('Add these to .env (once — changing them later unsubscribes every device):');
        $this->newLine();
        $this->line('WEBPUSH_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('WEBPUSH_PRIVATE_KEY='.$keys['privateKey']);
        $this->line('WEBPUSH_SUBJECT=mailto:admin@example.com');

        return self::SUCCESS;
    }
}
