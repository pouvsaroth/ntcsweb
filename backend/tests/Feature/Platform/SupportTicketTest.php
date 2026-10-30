<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Platform\Jobs\SendTicketTelegram;
use App\Platform\Models\Ticket;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

/** Stage 13b (docs/backoffice/stage-13-help-support.md): support tickets. */
class SupportTicketTest extends TestCase
{
    use HasAcademicAdmin, HasPlatformStaff, RefreshDatabase;

    private const BO = '/api/v1/platform/support/tickets';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
    }

    public function test_a_ticket_goes_from_school_to_newtech_and_back(): void
    {
        $user = $this->actingAsAdminWithPermissions([Permissions::HELP_SUPPORT_VIEW]);

        $this->getJson('/api/v1/support-tickets/options')->assertOk()->assertJsonFragment(['code' => 'academics']);
        $this->post('/api/v1/support-tickets', ['subject' => 'Cannot print invoices'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['priority', 'body']);
        $this->post('/api/v1/support-tickets', ['subject' => 'X', 'priority' => 'normal', 'body' => 'Y', 'module' => 'nope'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('module');

        $ticket = $this->post('/api/v1/support-tickets', [
            'subject' => 'Cannot print invoices', 'module' => 'finance', 'priority' => 'high',
            'body' => 'The Print button does nothing.', 'files' => [UploadedFile::fake()->image('error.png')],
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.status', 'open')->json('data');
        $this->assertSame('T-'.str_pad((string) $ticket['id'], 5, '0', STR_PAD_LEFT), $ticket['number']);
        Queue::assertPushed(SendTicketTelegram::class, fn ($job) => $job->kind === 'new');

        // Its own picture comes back to the user.
        $url = $this->getJson("/api/v1/support-tickets/{$ticket['id']}")->assertOk()->json('data.messages.0.attachments.0.url');
        $this->get($url)->assertOk();

        // NewTech answers: in progress, assigned to whoever answered, a dot for the user.
        $staff = $this->platformStaff(['platform.support.read', 'platform.support.tickets']);
        $this->actingAsStaff($staff);
        $this->getJson(self::BO.'/waiting')->assertOk()->assertJsonPath('data.waiting', 1);
        $this->platformStaff(['platform.support.read']); // may read tickets, not answer: not offered as assignee
        $this->getJson(self::BO.'/staff')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $staff->id);
        $this->getJson(self::BO.'?filter[status]=active&search=print')->assertOk()->assertJsonPath('data.0.customer.id', $this->tenant->id);
        $this->getJson(self::BO."?search=T-{$ticket['id']}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::BO."/{$ticket['id']}")->assertOk()->assertJsonPath('data.ticket.unread', 0);
        $this->post(self::BO."/{$ticket['id']}/messages", ['body' => 'Fixed, please try again.'], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.ticket.status', 'in_progress')->assertJsonPath('data.ticket.assignee', $staff->name);
        $this->patchJson(self::BO."/{$ticket['id']}", ['status' => 'resolved', 'priority' => 'normal'])
            ->assertOk()->assertJsonPath('data.ticket.status', 'resolved')->assertJsonPath('data.ticket.priority', 'normal');
        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'support.ticket_updated']);

        // The user sees the reply and the status change; a new reply opens it again.
        $this->actingAs($user);
        $this->getJson('/api/v1/support-tickets/unread')->assertOk()->assertJsonPath('data.unread', 2);
        // Resolved, but with news: still under Active until seen.
        $this->getJson('/api/v1/support-tickets?status=active')->assertOk()->assertJsonCount(1, 'data');
        $events = collect($this->getJson("/api/v1/support-tickets/{$ticket['id']}")->assertOk()->json('data.messages'))->pluck('event.status')->filter()->values()->all();
        $this->assertSame(['in_progress', 'resolved'], $events);
        $this->getJson('/api/v1/support-tickets/unread')->assertJsonPath('data.unread', 0);

        $this->post("/api/v1/support-tickets/{$ticket['id']}/messages", ['body' => 'Still broken on Chrome.'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.ticket.status', 'open');
        Queue::assertPushed(SendTicketTelegram::class, fn ($job) => $job->kind === 'reply');
        $this->postJson("/api/v1/support-tickets/{$ticket['id']}/close")->assertOk()->assertJsonPath('data.ticket.status', 'closed');
        $this->assertNotNull(Ticket::query()->find($ticket['id'])->closed_at);
        $this->getJson('/api/v1/support-tickets?status=done')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/support-tickets?status=active')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_only_the_owner_with_the_permission_and_only_answerers_reply(): void
    {
        $owner = $this->actingAsAdminWithPermissions([Permissions::HELP_SUPPORT_VIEW]);
        $id = $this->postJson('/api/v1/support-tickets', ['subject' => 'Mine', 'priority' => 'low', 'body' => 'Private'])->assertCreated()->json('data.id');

        // A colleague in the same school does not see it.
        $this->actingAsAdminWithPermissions([Permissions::HELP_SUPPORT_VIEW]);
        $this->getJson('/api/v1/support-tickets')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/support-tickets/{$id}")->assertNotFound();

        // No Help & Support in the role: no tickets.
        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/v1/support-tickets')->assertForbidden();
        $this->postJson('/api/v1/support-tickets', ['subject' => 'X', 'priority' => 'low', 'body' => 'Y'])->assertForbidden();

        // Read-only back office staff see it but cannot answer or change it.
        $this->actingAsStaff($this->platformStaff(['platform.support.read']));
        $this->getJson(self::BO."/{$id}")->assertOk()->assertJsonPath('data.ticket.user_name', $owner->name);
        $this->postJson(self::BO."/{$id}/messages", ['body' => 'Hi'])->assertNotFound();
        $this->patchJson(self::BO."/{$id}", ['status' => 'closed'])->assertNotFound();
    }
}
