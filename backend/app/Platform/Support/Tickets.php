<?php

declare(strict_types=1);

namespace App\Platform\Support;

use App\Models\User;
use App\Platform\Billing\Modules;
use App\Platform\Jobs\SendTicketTelegram;
use App\Platform\Models\PlatformStaff;
use App\Platform\Models\Ticket;
use App\Platform\Models\TicketMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Support tickets (docs/backoffice/stage-13-help-support.md, 13b).
 *
 * - A new ticket is open; NewTech's first reply moves it to in progress.
 * - NewTech marks it resolved or closed; the customer may close it too.
 * - A customer reply on a resolved or closed ticket opens it again.
 * - Every status change is a line in the thread, so both sides see it.
 * - Telegram tells NewTech about a new ticket and about each customer reply.
 */
final class Tickets
{
    public function __construct(private readonly PlatformAudit $audit) {}

    /**
     * @param  array{subject: string, module: ?string, priority: string, body: string}  $data
     * @param  list<UploadedFile>  $files
     */
    public function open(User $user, array $data, array $files): Ticket
    {
        $ticket = DB::connection((new Ticket)->getConnectionName())->transaction(function () use ($user, $data, $files) {
            $ticket = Ticket::query()->create([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->getKey(),
                'user_name' => $user->name,
                'user_email' => $user->email,
                'subject' => $data['subject'],
                'module' => $data['module'],
                'priority' => $data['priority'],
                'status' => Ticket::OPEN,
                'unread_staff' => 1,
                'last_reply_at' => now(),
            ]);
            $ticket->messages()->create([
                'sender' => TicketMessage::FROM_USER,
                'body' => $data['body'],
                'attachments' => $this->store($ticket, $files) ?: null,
            ]);

            return $ticket;
        });

        SendTicketTelegram::dispatch($ticket->getKey(), 'new')->afterCommit();

        return $ticket;
    }

    /** @param  list<UploadedFile>  $files */
    public function fromUser(Ticket $ticket, ?string $body, array $files): TicketMessage
    {
        $message = DB::connection($ticket->getConnectionName())->transaction(function () use ($ticket, $body, $files) {
            $reopen = ! $ticket->isActive();
            if ($reopen) {
                $this->event($ticket, TicketMessage::FROM_USER, null, Ticket::OPEN);
            }
            $message = $ticket->messages()->create([
                'sender' => TicketMessage::FROM_USER,
                'body' => $body,
                'attachments' => $this->store($ticket, $files) ?: null,
            ]);
            $ticket->forceFill([
                'status' => $reopen ? Ticket::OPEN : $ticket->status,
                'resolved_at' => $reopen ? null : $ticket->resolved_at,
                'closed_at' => null,
                'unread_staff' => $ticket->unread_staff + 1,
                'last_reply_at' => now(),
            ])->save();

            return $message;
        });

        SendTicketTelegram::dispatch($ticket->getKey(), 'reply')->afterCommit();

        return $message;
    }

    /** @param  list<UploadedFile>  $files */
    public function fromStaff(Ticket $ticket, PlatformStaff $staff, ?string $body, array $files): TicketMessage
    {
        return DB::connection($ticket->getConnectionName())->transaction(function () use ($ticket, $staff, $body, $files) {
            // The first answer: NewTech is on it.
            if ($ticket->status === Ticket::OPEN) {
                $this->event($ticket, TicketMessage::FROM_STAFF, $staff, Ticket::IN_PROGRESS);
                $ticket->status = Ticket::IN_PROGRESS;
            }
            $message = $ticket->messages()->create([
                'sender' => TicketMessage::FROM_STAFF,
                'staff_id' => $staff->getKey(),
                'body' => $body,
                'attachments' => $this->store($ticket, $files) ?: null,
            ]);
            $ticket->forceFill([
                'assigned_staff_id' => $ticket->assigned_staff_id ?? $staff->getKey(),
                'unread_staff' => 0,
                'unread_user' => $ticket->unread_user + 1,
                'last_reply_at' => now(),
            ])->save();
            $this->audit->record('support.ticket_replied', $ticket, new: ['files' => count($files)], tenantId: $ticket->tenant_id, actor: $staff);

            return $message;
        });
    }

    /** A status change by NewTech ($staff) or by the customer (null). */
    public function setStatus(Ticket $ticket, string $status, ?PlatformStaff $staff): void
    {
        if ($ticket->status === $status) {
            return;
        }
        DB::connection($ticket->getConnectionName())->transaction(function () use ($ticket, $status, $staff) {
            $this->event($ticket, $staff ? TicketMessage::FROM_STAFF : TicketMessage::FROM_USER, $staff, $status);
            $ticket->forceFill([
                'status' => $status,
                'resolved_at' => match ($status) {
                    Ticket::RESOLVED => now(),
                    Ticket::CLOSED => $ticket->resolved_at,
                    default => null,
                },
                'closed_at' => $status === Ticket::CLOSED ? now() : null,
                // The customer should see NewTech's change; their own needs no dot.
                'unread_user' => $staff ? $ticket->unread_user + 1 : $ticket->unread_user,
            ])->save();
        });
    }

    private function event(Ticket $ticket, string $sender, ?PlatformStaff $staff, string $status): void
    {
        $ticket->messages()->create([
            'sender' => $sender,
            'staff_id' => $staff?->getKey(),
            'event' => ['status' => $status],
        ]);
    }

    /**
     * @param  list<UploadedFile>  $files
     * @return list<array{path: string, name: string, mime: string, size: int}>
     */
    private function store(Ticket $ticket, array $files): array
    {
        $out = [];
        foreach ($files as $file) {
            $path = $file->storeAs($ticket->folder(), Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: 'bin'), 'local');
            if ($path === false) {
                throw new \RuntimeException('Failed to store the attachment.');
            }
            $out[] = [
                'path' => $path,
                'name' => Str::limit($file->getClientOriginalName(), 150, ''),
                'mime' => (string) $file->getMimeType(),
                'size' => (int) $file->getSize(),
            ];
        }

        return $out;
    }

    /**
     * A ticket as the lists show it. `$forStaff` = the back office (its unread
     * count and the customer school).
     *
     * @return array<string, mixed>
     */
    public static function summary(Ticket $t, bool $forStaff): array
    {
        $catalog = Modules::catalog();

        return [
            'id' => $t->id,
            'number' => $t->number(),
            'subject' => $t->subject,
            'module' => $t->module,
            'module_label' => $t->module ? ($catalog[$t->module]['label'] ?? $t->module) : null,
            'module_label_km' => $t->module ? ($catalog[$t->module]['label_km'] ?? null) : null,
            'priority' => $t->priority,
            'status' => $t->status,
            'unread' => $forStaff ? $t->unread_staff : $t->unread_user,
            'user_name' => $t->user_name,
            'user_email' => $t->user_email,
            'assignee' => $t->relationLoaded('assignee') ? $t->assignee?->name : null,
            'assigned_staff_id' => $forStaff ? $t->assigned_staff_id : null,
            'customer' => $forStaff && $t->relationLoaded('tenant') && $t->tenant ? ['id' => $t->tenant->id, 'name' => $t->tenant->name, 'slug' => $t->tenant->slug] : null,
            'last_reply_at' => $t->last_reply_at?->toIso8601String(),
            'resolved_at' => $t->resolved_at?->toIso8601String(),
            'closed_at' => $t->closed_at?->toIso8601String(),
            'created_at' => $t->created_at?->toIso8601String(),
        ];
    }

    /**
     * One thread entry; `$fileUrl` builds each attachment's download link.
     *
     * @param  \Closure(TicketMessage, int): string  $fileUrl
     * @return array<string, mixed>
     */
    public static function present(TicketMessage $m, \Closure $fileUrl): array
    {
        return [
            'id' => $m->id,
            'sender' => $m->sender,
            'staff' => $m->sender === TicketMessage::FROM_STAFF ? ($m->staff?->name ?? 'NewTech') : null,
            'body' => $m->body,
            'event' => $m->event,
            'attachments' => array_map(fn (array $a, int $i) => [
                'name' => $a['name'],
                'mime' => $a['mime'],
                'size' => $a['size'],
                'is_image' => str_starts_with($a['mime'], 'image/'),
                'url' => $fileUrl($m, $i),
            ], $m->attachments ?? [], array_keys($m->attachments ?? [])),
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }

    /** Send one attachment (inline for an image or PDF, as a download otherwise). */
    public static function download(TicketMessage $message, int $index): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $file = ($message->attachments ?? [])[$index] ?? abort(404);
        abort_unless(Storage::disk('local')->exists($file['path']), 404);
        $inline = str_starts_with($file['mime'], 'image/') || $file['mime'] === 'application/pdf';

        return Storage::disk('local')->response($file['path'], $file['name'], ['Content-Type' => $file['mime']], $inline ? 'inline' : 'attachment');
    }
}
