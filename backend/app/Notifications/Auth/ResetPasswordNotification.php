<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Models\User;
use App\Support\Tenancy\TenantUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Queued: sending mail inline would make the forgot-password endpoint's
 * response time depend on the mail provider, which also leaks whether an
 * account exists.
 */
class ResetPasswordNotification extends Notification implements ShouldQueue
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

        $url = app(TenantUrl::class)->frontend($tenant, '/reset-password', array_filter([
            'token' => $this->token,
            'email' => $notifiable->email,
            // Carried explicitly for the local/central-domain case, where the
            // hostname alone cannot identify the school.
            'tenant' => $tenant?->slug,
        ]));

        $minutes = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject(__('Reset your :app password', ['app' => $tenant?->name ?? config('app.name')]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('You are receiving this email because we received a password reset request for your account.'))
            ->action(__('Reset password'), $url)
            ->line(__('This link will expire in :count minutes.', ['count' => $minutes]))
            ->line(__('If you did not request a password reset, no further action is required.'));
    }
}
