<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Role;
use App\Models\User;
use App\Platform\Jobs\SendChatTelegram;
use App\Platform\Models\Chat;
use App\Platform\Models\SupportSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

/**
 * Stage 10 (docs/backoffice/stage-10-support-chat.md): the support chat.
 */
class SupportChatTest extends TestCase
{
    use HasAcademicAdmin, HasPlatformStaff, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function ask(array $data = ['body' => 'How do I add a class?'])
    {
        return $this->post('/api/v1/support-chat', $data, ['Accept' => 'application/json']);
    }

    public function test_a_staff_user_asks_with_a_screenshot_and_a_file(): void
    {
        Queue::fake();
        $this->actingAsAdminWithPermissions([]);

        $this->ask(['body' => 'Error on enrollments', 'files' => [UploadedFile::fake()->image('screen.png'), UploadedFile::fake()->create('list.pdf', 200, 'application/pdf')]])
            ->assertCreated()
            ->assertJsonPath('data.sender', 'user')
            ->assertJsonPath('data.attachments.0.is_image', true)
            ->assertJsonPath('data.attachments.1.name', 'list.pdf');

        $chat = Chat::query()->firstOrFail();
        $this->assertTrue($chat->waiting);
        $this->assertSame(1, $chat->unread_staff);
        $this->assertSame($this->tenant->id, $chat->tenant_id);
        Queue::assertPushed(SendChatTelegram::class);

        // The picture comes back to its owner.
        $url = $this->getJson('/api/v1/support-chat')->assertOk()->json('data.messages.0.attachments.0.url');
        $this->get($url)->assertOk();

        // A second line while still waiting: no second Telegram alert.
        $this->ask(['body' => 'Also this'])->assertCreated();
        Queue::assertPushed(SendChatTelegram::class, 1);
    }

    public function test_an_empty_message_or_a_wrong_file_is_refused(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->ask(['body' => '  '])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->ask(['files' => [UploadedFile::fake()->create('virus.exe', 10)]])->assertUnprocessable()->assertJsonValidationErrors('files.0');
        $this->ask(['files' => [UploadedFile::fake()->create('big.pdf', 11000, 'application/pdf')]])->assertUnprocessable()->assertJsonValidationErrors('files.0');
    }

    public function test_students_cannot_use_the_chat(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $student = Role::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('slug', Role::STUDENT)->first()
            ?? Role::factory()->forTenant($this->tenant)->create(['name' => 'Student', 'slug' => Role::STUDENT]);
        $user = User::factory()->forTenant($this->tenant)->create();
        $user->attachRoles($student);
        $this->actingAsTenantUser($user);

        $this->ask()->assertForbidden();
        $this->getJson('/api/v1/support-chat')->assertForbidden();
    }

    public function test_nobody_reads_another_persons_chat_or_files(): void
    {
        Queue::fake();
        $this->actingAsAdminWithPermissions([]);
        $url = $this->ask(['files' => [UploadedFile::fake()->image('a.png')]])->json('data.attachments.0.url');

        $this->actingAsAdminWithPermissions([]); // another user of the same school
        $this->getJson('/api/v1/support-chat')->assertOk()->assertJsonCount(0, 'data.messages');
        $this->get($url)->assertNotFound();
    }

    public function test_newtech_answers_from_the_back_office_and_the_user_sees_it(): void
    {
        Queue::fake();
        $user = $this->actingAsAdminWithPermissions([]);
        $this->ask()->assertCreated();
        $chat = Chat::query()->firstOrFail();

        $this->actingAsStaff($staff = $this->platformStaff());
        $this->getJson('/api/v1/platform/support/chats/waiting')->assertJsonPath('data.waiting', 1);
        $this->getJson('/api/v1/platform/support/chats?filter[waiting]=1')->assertOk()
            ->assertJsonPath('data.0.user_name', $user->name)
            ->assertJsonPath('data.0.last_message.text', 'How do I add a class?');
        $this->getJson("/api/v1/platform/support/chats/{$chat->id}")->assertOk()->assertJsonCount(1, 'data.messages');
        $this->assertSame(0, $chat->fresh()->unread_staff);

        $this->post("/api/v1/platform/support/chats/{$chat->id}/messages", ['body' => 'Go to Academic → Classes.', 'files' => [UploadedFile::fake()->image('how.png')]], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.staff', $staff->name);
        $this->assertFalse($chat->fresh()->waiting);
        $this->getJson('/api/v1/platform/support/chats/waiting')->assertJsonPath('data.waiting', 0);

        // Back in the system: a dot, the answer, then read.
        $this->actingAsTenantUser($user);
        $this->getJson('/api/v1/support-chat/unread')->assertJsonPath('data.unread', 1);
        $reply = $this->getJson('/api/v1/support-chat')->json('data.messages.1');
        $this->assertSame('Go to Academic → Classes.', $reply['body']);
        $this->get($reply['attachments'][0]['url'])->assertOk();
        $this->postJson('/api/v1/support-chat/read')->assertOk();
        $this->getJson('/api/v1/support-chat/unread')->assertJsonPath('data.unread', 0);

        // A new question after the answer alerts Telegram again.
        $this->ask(['body' => 'Thanks, one more'])->assertCreated();
        Queue::assertPushed(SendChatTelegram::class, 2);
    }

    public function test_closing_and_a_new_message_reopens(): void
    {
        Queue::fake();
        $this->actingAsAdminWithPermissions([]);
        $this->ask()->assertCreated();
        $chat = Chat::query()->firstOrFail();

        $this->actingAsStaff($this->platformStaff());
        $this->postJson("/api/v1/platform/support/chats/{$chat->id}/close")->assertOk()->assertJsonPath('data.status', 'closed');

        $this->actingAsTenantUser($this->admin);
        $this->ask(['body' => 'Back again'])->assertCreated();
        $this->assertSame(Chat::OPEN, $chat->fresh()->status);
    }

    public function test_read_only_staff_see_chats_but_cannot_answer(): void
    {
        Queue::fake();
        $this->actingAsAdminWithPermissions([]);
        $this->ask()->assertCreated();
        $chat = Chat::query()->firstOrFail();

        $this->actingAsStaff($this->platformStaff(['platform.support.read']));
        $this->getJson("/api/v1/platform/support/chats/{$chat->id}")->assertOk();
        $this->postJson("/api/v1/platform/support/chats/{$chat->id}/messages", ['body' => 'x'])->assertNotFound();
        $this->getJson('/api/v1/platform/support/chats/settings')->assertNotFound();
    }

    public function test_staff_set_telegram_and_test_it(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $this->actingAsStaff($this->platformStaff());

        $this->putJson('/api/v1/platform/support/chats/settings', ['telegram_bot_token' => '123:ABC', 'telegram_chat_id' => '-100123'])->assertOk()
            ->assertJsonPath('data.telegram_token_set', true)
            ->assertJsonMissingPath('data.telegram_bot_token');
        $this->assertSame('123:ABC', SupportSettings::current()->telegram_bot_token);
        $this->postJson('/api/v1/platform/support/chats/settings/test')->assertJsonPath('data.ok', true);
        $this->putJson('/api/v1/platform/support/chats/settings', ['telegram_chat_id' => 'not a chat'])->assertUnprocessable();
    }

    public function test_a_question_reaches_the_telegram_group(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        SupportSettings::current()->update(['telegram_bot_token' => '123:ABC', 'telegram_chat_id' => '-100123']);

        $this->actingAsAdminWithPermissions([]);
        $this->ask(['body' => 'Hello support'])->assertCreated();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'bot123:ABC/sendMessage') && $r['chat_id'] === '-100123' && str_contains($r['text'], 'Hello support'));
    }

    public function test_a_message_from_a_school_in_cambodia_time_is_stored_in_utc(): void
    {
        Queue::fake();
        $this->actingAsAdminWithPermissions([]);
        $zone = date_default_timezone_get();
        date_default_timezone_set('Asia/Phnom_Penh'); // as a console request runs
        try {
            $this->ask(['body' => 'What time is it?'])->assertCreated();
        } finally {
            date_default_timezone_set($zone);
        }

        $stored = \Illuminate\Support\Carbon::parse((string) \Illuminate\Support\Facades\DB::connection(config('tenancy.database.central_connection'))
            ->table('bo_chat_messages')->value('created_at'), 'UTC');
        $this->assertLessThan(120, abs($stored->diffInSeconds(now('UTC'))), 'stored as UTC, not Phnom Penh time');
    }
}
