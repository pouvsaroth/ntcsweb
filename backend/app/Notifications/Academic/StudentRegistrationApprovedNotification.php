<?php

declare(strict_types=1);

namespace App\Notifications\Academic;

use App\Models\Student;
use App\Models\User;
use App\Support\Tenancy\TenantUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent once, right after StudentRegistrationService::approve() commits — see
 * ResetPasswordNotification for why this is queued rather than sent inline.
 */
class StudentRegistrationApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Student $student) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $notifiable */
        $tenant = $notifiable->tenant;

        $url = app(TenantUrl::class)->frontend($tenant, '/login', array_filter([
            'tenant' => $tenant?->slug,
        ]));

        return (new MailMessage)
            ->subject(__('Your :app registration is approved', ['app' => $tenant?->name ?? config('app.name')]))
            ->greeting(__('Hello :name,', ['name' => $this->student->fullName()]))
            ->line(__('Your registration has been reviewed and your payment confirmed — welcome aboard!'))
            ->line(__('You can now log in with the password you chose during registration.'))
            ->action(__('Log in'), $url)
            ->line(__('Once logged in, you can download your invoice from your account at any time.'));
    }
}
