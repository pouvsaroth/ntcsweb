<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Jobs\SendPushNotificationJob;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\NotificationService;
use App\Support\Notifications\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * Phone notifications (Web Push) — see MyPushSubscriptionController and
 * SendPushNotificationJob.
 */
class PushNotificationTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private function subscribe(array $overrides = []): array
    {
        return [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/device-'.fake()->uuid(),
            'keys' => ['p256dh' => 'BPublicKey', 'auth' => 'AuthSecret'],
            ...$overrides,
        ];
    }

    public function test_the_config_endpoint_returns_the_public_key(): void
    {
        config(['services.webpush.public_key' => 'BTestPublicKey']);
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/v1/my-push-subscriptions/config')->assertOk()->assertJsonPath('data.public_key', 'BTestPublicKey');
    }

    public function test_a_user_can_subscribe_a_device_and_resubscribing_does_not_duplicate_it(): void
    {
        $admin = $this->actingAsAdminWithPermissions([]);
        $payload = $this->subscribe();

        $this->postJson('/api/v1/my-push-subscriptions', $payload)->assertOk();
        $this->postJson('/api/v1/my-push-subscriptions', $payload)->assertOk();

        $this->assertSame(1, PushSubscription::query()->count());
        $this->assertSame($admin->id, PushSubscription::query()->first()->user_id);
    }

    public function test_a_shared_device_moves_to_whoever_subscribed_it_last(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $payload = $this->subscribe();
        $this->postJson('/api/v1/my-push-subscriptions', $payload)->assertOk();

        $other = User::factory()->forTenant($this->tenant)->create();
        $this->actingAsTenantUser($other);
        $this->postJson('/api/v1/my-push-subscriptions', $payload)->assertOk();

        $this->assertSame(1, PushSubscription::query()->count());
        $this->assertSame($other->id, PushSubscription::query()->first()->user_id);
    }

    public function test_a_user_can_unsubscribe_their_own_device(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $payload = $this->subscribe();
        $this->postJson('/api/v1/my-push-subscriptions', $payload)->assertOk();

        $this->deleteJson('/api/v1/my-push-subscriptions', ['endpoint' => $payload['endpoint']])->assertOk();

        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_subscribing_validates_the_payload(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->postJson('/api/v1/my-push-subscriptions', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['endpoint', 'keys.p256dh', 'keys.auth']);
    }

    public function test_every_notification_is_pushed_to_a_subscribed_recipient(): void
    {
        Queue::fake();
        config(['services.webpush.public_key' => 'BTestPublicKey']);
        $admin = $this->actingAsAdminWithPermissions([]);
        $this->postJson('/api/v1/my-push-subscriptions', $this->subscribe())->assertOk();

        app(NotificationService::class)->notify($admin, NotificationType::LEAVE_REQUEST_SUBMITTED, ['student_name' => 'Dara']);

        Queue::assertPushed(SendPushNotificationJob::class, 1);
    }

    public function test_nothing_is_pushed_without_a_subscription_or_without_keys(): void
    {
        Queue::fake();
        $admin = $this->actingAsAdminWithPermissions([]);

        config(['services.webpush.public_key' => 'BTestPublicKey']);
        app(NotificationService::class)->notify($admin, NotificationType::LEAVE_REQUEST_SUBMITTED, ['student_name' => 'Dara']);

        $this->postJson('/api/v1/my-push-subscriptions', $this->subscribe())->assertOk();
        config(['services.webpush.public_key' => null]);
        app(NotificationService::class)->notify($admin, NotificationType::LEAVE_REQUEST_SUBMITTED, ['student_name' => 'Dara']);

        Queue::assertNothingPushed();
    }

    public function test_the_push_text_is_in_the_recipients_language(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $recipient = User::factory()->forTenant($this->tenant)->create(['locale' => 'km']);

        $notification = UserNotification::query()->create([
            'recipient_id' => $recipient->id,
            'type' => NotificationType::MAKE_UP_CLASS_REQUEST_SUBMITTED,
            'data' => ['student_name' => 'Dara', 'student_id' => 5],
            'link' => '/admin/approvals/queue',
        ]);

        $payload = SendPushNotificationJob::payload($notification->load('recipient'), $this->tenant);

        $this->assertSame('Dara បានដាក់ស្នើសំណើសុំរៀនសង', $payload['body']);
        $this->assertSame('/admin/approvals/queue', $payload['url']);
        $this->assertSame($this->tenant->name, $payload['title']);
    }

    public function test_an_unknown_type_falls_back_to_generic_text(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $recipient = User::factory()->forTenant($this->tenant)->create(['locale' => 'en']);

        $notification = UserNotification::query()->create([
            'recipient_id' => $recipient->id,
            'type' => 'something_new',
            'data' => [],
        ]);

        $payload = SendPushNotificationJob::payload($notification->load('recipient'), $this->tenant);

        $this->assertSame('You have a new notification', $payload['body']);
        $this->assertSame('/admin/notifications', $payload['url']);
    }
}
