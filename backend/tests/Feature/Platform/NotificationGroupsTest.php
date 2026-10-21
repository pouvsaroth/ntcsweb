<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\NotificationGroup;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Models\SupportSettings;
use App\Services\Notifications\NotificationService;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * Stage 11 (docs/backoffice/stage-11-telegram-notifications.md): bell
 * notifications also go to the school's Telegram notification groups.
 */
class NotificationGroupsTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        SupportSettings::current()->update(['telegram_bot_token' => '123:ABC']);
        Http::fake(fn ($request) => Http::response(['ok' => true, 'result' => str_contains($request->url(), 'getUpdates') ? $this->updates : []]));
    }

    /** What the next getUpdates returns. */
    private array $updates = [];

    private function group(array $users, ?string $chat = '-100555'): NotificationGroup
    {
        $g = NotificationGroup::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Admins', 'telegram_chat_id' => $chat]);
        $g->users()->sync(collect($users)->map->getKey()->all());

        return $g;
    }

    private function sentTexts(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn ($r) => str_contains($r->url(), '/sendMessage'))->map(fn ($r) => [$r['chat_id'], $r['text']])->values()->all();
    }

    public function test_a_bell_notification_also_goes_to_the_members_telegram_group(): void
    {
        $this->tenant = Tenant::factory()->create(['locale' => 'en']);
        $admin = $this->actingAsAdminWithPermissions([Permissions::TENANT_SETTINGS_UPDATE]);
        $this->group([$admin]);

        app(NotificationService::class)->notify($admin, 'leave_request_submitted', ['student_name' => 'Sok Dara'], '/admin/leave-requests/5');

        $sent = $this->sentTexts();
        $this->assertCount(1, $sent);
        $this->assertSame('-100555', $sent[0][0]);
        $this->assertStringContainsString('Sok Dara submitted a permission request', $sent[0][1]);
        $this->assertStringContainsString('/admin/leave-requests/5', $sent[0][1]);
    }

    public function test_several_members_of_one_group_get_one_telegram_message_and_khmer_schools_get_khmer(): void
    {
        $this->tenant = Tenant::factory()->create(['locale' => 'km']); // a Khmer school from the start
        $admin = $this->actingAsAdminWithPermissions([]);
        $other = User::factory()->forTenant($this->tenant)->create();
        $this->group([$admin, $other]);

        app(NotificationService::class)->notifyMany([$admin, $other], 'leave_request_submitted', ['student_name' => 'Sok Dara'], '/admin/x');

        $sent = $this->sentTexts();
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('Sok Dara បានដាក់ស្នើសំណើសុំច្បាប់', $sent[0][1]);
    }

    public function test_nothing_is_sent_without_a_linked_group_or_a_bot(): void
    {
        $admin = $this->actingAsAdminWithPermissions([]);
        $this->group([$admin], null); // not linked

        app(NotificationService::class)->notify($admin, 'leave_request_submitted', ['student_name' => 'A'], null);
        $this->assertCount(0, $this->sentTexts());

        $this->group([$admin]);
        SupportSettings::current()->update(['telegram_bot_token' => null]);
        app(NotificationService::class)->notify($admin, 'leave_request_submitted', ['student_name' => 'B'], null);
        $this->assertCount(0, $this->sentTexts());
    }

    public function test_the_school_manages_its_groups_and_links_one_with_a_code(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::TENANT_SETTINGS_UPDATE]);

        $id = $this->postJson('/api/v1/settings/notification-groups', ['name' => 'HR', 'user_ids' => [$admin->id]])->assertCreated()
            ->assertJsonPath('data.users.0.id', $admin->id)->json('data.id');
        $code = $this->postJson("/api/v1/settings/notification-groups/{$id}/link-code")->assertOk()->json('data.link_code');
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{6}$/', $code);

        // Someone sends "/link CODE" in the Telegram group.
        $this->updates = [[
            'update_id' => 41,
            'message' => ['text' => "/link@newtechkh_bot {$code}", 'chat' => ['id' => -100777, 'type' => 'supergroup', 'title' => 'School HR']],
        ]];
        $this->postJson("/api/v1/settings/notification-groups/{$id}/check")->assertOk()
            ->assertJsonPath('data.linked', true)
            ->assertJsonPath('data.telegram_title', 'School HR')
            ->assertJsonPath('data.link_code', null);
        $this->assertSame(42, SupportSettings::current()->telegram_update_offset);

        $this->postJson("/api/v1/settings/notification-groups/{$id}/test")->assertOk()->assertJsonPath('data.ok', true);
        $this->postJson("/api/v1/settings/notification-groups/{$id}/unlink")->assertOk()->assertJsonPath('data.linked', false);
        $this->deleteJson("/api/v1/settings/notification-groups/{$id}")->assertNoContent();
    }

    public function test_an_expired_or_unknown_code_links_nothing(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TENANT_SETTINGS_UPDATE]);
        $g = NotificationGroup::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Old', 'link_code' => 'ABC123', 'link_code_expires_at' => now()->subMinute()]);
        $this->updates = [
            ['update_id' => 1, 'message' => ['text' => '/link ABC123', 'chat' => ['id' => -1, 'type' => 'group', 'title' => 'X']]],
            ['update_id' => 2, 'message' => ['text' => '/link ZZZ999', 'chat' => ['id' => -2, 'type' => 'group', 'title' => 'Y']]],
        ];

        $this->artisan('platform:telegram-tick')->assertSuccessful();
        $this->assertNull($g->fresh()->telegram_chat_id);
    }

    public function test_only_this_schools_users_and_groups_and_only_with_the_settings_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TENANT_SETTINGS_UPDATE]);
        $otherSchool = Tenant::factory()->create();
        $stranger = User::factory()->forTenant($otherSchool)->create();
        $foreign = NotificationGroup::query()->create(['tenant_id' => $otherSchool->id, 'name' => 'Theirs']);

        $this->postJson('/api/v1/settings/notification-groups', ['name' => 'X', 'user_ids' => [$stranger->id]])->assertUnprocessable()->assertJsonValidationErrors('user_ids');
        $this->putJson("/api/v1/settings/notification-groups/{$foreign->id}", ['name' => 'Mine'])->assertNotFound();
        $this->getJson('/api/v1/settings/notification-groups')->assertOk()->assertJsonCount(0, 'data.groups');

        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/v1/settings/notification-groups')->assertForbidden();
    }
}
