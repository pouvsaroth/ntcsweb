<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Models\User;
use App\Platform\Support\InviteLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The back office's invite to a new school's owner: a link to the same
 * set-password page a reset uses, good for `platform.invite_days`. The owner
 * chooses their own password — nobody at the platform ever knows it.
 */
class SchoolOwnerInviteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $notifiable */
        $tenant = $notifiable->tenant;

        $url = app(InviteLink::class)->for($notifiable, $this->token);

        return (new MailMessage)
            ->subject(__('You are invited to manage :school', ['school' => $tenant?->name ?? config('app.name')]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__(':school is ready on the school management system, and you are its administrator.', ['school' => $tenant?->name]))
            ->action(__('Set your password'), $url)
            ->line(__('This link will expire in :count days.', ['count' => (int) config('platform.invite_days', 7)]))
            ->line(__('If you were not expecting this, you can ignore this email.'));
    }
}
