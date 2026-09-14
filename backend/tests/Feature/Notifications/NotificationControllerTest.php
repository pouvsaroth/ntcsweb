<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Models\UserNotification;
use App\Support\Notifications\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private function notify(User $recipient, bool $read = false): UserNotification
    {
        return UserNotification::query()->create([
            'recipient_id' => $recipient->id,
            'type' => NotificationType::LEAVE_REQUEST_SUBMITTED,
            'data' => ['student_id' => 1, 'student_name' => 'Test Student'],
            'link' => '/admin/approvals/queue',
            'read_at' => $read ? now() : null,
        ]);
    }

    public function test_a_user_sees_only_their_own_notifications_with_an_unread_count(): void
    {
        $admin = $this->actingAsAdminWithPermissions([]);
        $other = User::factory()->forTenant($this->tenant)->create();

        $this->notify($admin);
        $this->notify($admin, read: true);
        $this->notify($other);

        $response = $this->getJson('/api/v1/notifications')->assertOk();

        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.unread_count', 1);
    }

    public function test_marking_one_notification_read_only_affects_that_one(): void
    {
        $admin = $this->actingAsAdminWithPermissions([]);
        $first = $this->notify($admin);
        $second = $this->notify($admin);

        $this->postJson("/api/v1/notifications/{$first->id}/read")->assertOk();

        $this->assertNotNull($first->fresh()->read_at);
        $this->assertNull($second->fresh()->read_at);
    }

    public function test_a_user_cannot_mark_someone_elses_notification_read(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $other = User::factory()->forTenant($this->tenant)->create();
        $notification = $this->notify($other);

        $this->postJson("/api/v1/notifications/{$notification->id}/read")->assertNotFound();
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_mark_all_read_only_affects_the_current_users_notifications(): void
    {
        $admin = $this->actingAsAdminWithPermissions([]);
        $other = User::factory()->forTenant($this->tenant)->create();

        $this->notify($admin);
        $this->notify($admin);
        $otherNotification = $this->notify($other);

        $this->postJson('/api/v1/notifications/mark-all-read')->assertOk();

        $this->assertSame(0, UserNotification::where('recipient_id', $admin->id)->unread()->count());
        $this->assertNull($otherNotification->fresh()->read_at);
    }
}
