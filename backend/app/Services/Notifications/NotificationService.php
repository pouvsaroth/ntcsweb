<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Collection;

/**
 * The one place a UserNotification is ever created — see that model's own
 * docblock for why `type`/`data` rather than pre-rendered text.
 */
final class NotificationService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function notify(User $recipient, string $type, array $data, ?string $link = null): UserNotification
    {
        return UserNotification::query()->create([
            'recipient_id' => $recipient->getKey(),
            'type' => $type,
            'data' => $data,
            'link' => $link,
        ]);
    }

    /**
     * @param  iterable<User>  $recipients
     * @param  array<string, mixed>  $data
     */
    public function notifyMany(iterable $recipients, string $type, array $data, ?string $link = null): void
    {
        // Deduped by user id — e.g. a school admin who is also the assigned
        // teacher would otherwise be notified twice for the same event.
        Collection::make($recipients)
            ->unique(fn (User $user) => $user->getKey())
            ->each(fn (User $user) => $this->notify($user, $type, $data, $link));
    }
}
