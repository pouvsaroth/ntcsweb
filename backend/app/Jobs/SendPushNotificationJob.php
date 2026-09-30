<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Models\Tenant;
use App\Models\UserNotification;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Stancl\Tenancy\Database\DatabaseManager;

/**
 * Delivers one UserNotification to every device its recipient turned phone
 * notifications on for (Web Push) — dispatched by NotificationService::notify(),
 * so every notification type reaches the phone with no per-type wiring.
 * Queued because each device is a network call to Google/Apple/Mozilla's
 * push service. The text is rendered here, in the recipient's own locale,
 * from lang/{locale}/notifications.php — the in-app bell renders the same
 * `type`/`data` client-side instead.
 *
 * Re-establishes TenantContext explicitly, same reasoning and pattern as
 * SendInvoiceNotificationJob. A subscription the push service reports as
 * gone (404/410 — the user revoked permission or uninstalled) is deleted so
 * it isn't retried forever.
 */
final class SendPushNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(
        private readonly int $notificationId,
        private readonly int $tenantId,
    ) {}

    public function handle(TenantContext $context, DatabaseManager $tenantDatabases): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);

        if (! app()->environment('testing')) {
            $tenantDatabases->createTenantConnection($tenant);
        }

        try {
            $context->runFor($tenant, fn () => $this->send($tenant));
        } finally {
            if (! app()->environment('testing')) {
                $tenantDatabases->purgeTenantConnection();
            }
        }
    }

    /**
     * What the phone shows — the school's name as the title, the message in
     * the recipient's own locale, and where a tap opens (see public/sw.js).
     *
     * @return array{title:string, body:string, url:string, tag:string}
     */
    public static function payload(UserNotification $notification, Tenant $tenant): array
    {
        $locale = $notification->recipient?->locale ?: config('app.locale');
        $replace = collect($notification->data ?? [])->filter(fn ($value) => is_scalar($value))->map(fn ($value) => (string) $value)->all();
        $key = "notifications.{$notification->type}";
        $body = __($key, $replace, $locale);

        return [
            'title' => $tenant->name,
            'body' => $body === $key ? __('notifications.fallback', [], $locale) : $body,
            'url' => $notification->link ?? '/admin/notifications',
            'tag' => "notification-{$notification->id}",
        ];
    }

    private function send(Tenant $tenant): void
    {
        $notification = UserNotification::query()->with('recipient')->find($this->notificationId);

        if ($notification === null || $notification->recipient === null) {
            return;
        }

        $subscriptions = PushSubscription::query()->where('user_id', $notification->recipient_id)->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $payload = json_encode(self::payload($notification, $tenant), JSON_THROW_ON_ERROR);

        $webPush = new WebPush(['VAPID' => [
            'subject' => config('services.webpush.subject'),
            'publicKey' => config('services.webpush.public_key'),
            'privateKey' => config('services.webpush.private_key'),
        ]]);

        foreach ($subscriptions as $subscription) {
            $webPush->queueNotification(Subscription::create([
                'endpoint' => $subscription->endpoint,
                'publicKey' => $subscription->public_key,
                'authToken' => $subscription->auth_token,
                'contentEncoding' => $subscription->content_encoding,
            ]), $payload);
        }

        foreach ($webPush->flush() as $report) {
            if ($report->isSubscriptionExpired()) {
                PushSubscription::query()->where('endpoint_hash', PushSubscription::hashEndpoint($report->getEndpoint()))->delete();
            } elseif (! $report->isSuccess()) {
                Log::warning('Web push delivery failed', ['notification_id' => $notification->id, 'reason' => $report->getReason()]);
            }
        }
    }
}
