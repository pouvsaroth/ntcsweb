<?php

declare(strict_types=1);

namespace App\Platform\Support;

use App\Models\User;
use App\Platform\Jobs\SendChatTelegram;
use App\Platform\Models\Chat;
use App\Platform\Models\ChatMessage;
use App\Platform\Models\PlatformStaff;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Support chat (docs/backoffice/stage-10-support-chat.md): a customer staff
 * user writes to NewTech; NewTech staff answer from the back office.
 */
final class Chats
{
    /** Images, PDF, Word, Excel, CSV, text — up to 10 MB each, 5 per message. */
    public const FILE_RULES = ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp,gif,pdf,doc,docx,xls,xlsx,csv,txt'];

    public const MAX_FILES = 5;

    public function __construct(private readonly PlatformAudit $audit) {}

    /** The user's chat, made on first use. */
    public function forUser(User $user): Chat
    {
        return Chat::query()->firstOrCreate(
            ['user_id' => $user->getKey()],
            ['tenant_id' => $user->tenant_id, 'user_name' => $user->name, 'user_email' => $user->email, 'status' => Chat::OPEN],
        );
    }

    /**
     * A customer writes. Reopens a closed chat; tells NewTech on Telegram when
     * it starts a new question (not on every line of a conversation already waiting).
     *
     * @param  list<UploadedFile>  $files
     */
    public function fromUser(User $user, ?string $body, array $files): ChatMessage
    {
        $chat = $this->forUser($user);
        $wasWaiting = $chat->waiting && $chat->status === Chat::OPEN;

        $message = DB::connection($chat->getConnectionName())->transaction(function () use ($chat, $user, $body, $files) {
            $message = $chat->messages()->create([
                'sender' => ChatMessage::FROM_USER,
                'body' => $body,
                'attachments' => $this->store($chat, $files) ?: null,
            ]);
            $chat->forceFill([
                'status' => Chat::OPEN,
                'waiting' => true,
                'unread_staff' => $chat->unread_staff + 1,
                'user_name' => $user->name,
                'user_email' => $user->email,
                'last_message_at' => now(),
            ])->save();

            return $message;
        });

        if (! $wasWaiting) {
            SendChatTelegram::dispatch($message->getKey())->afterCommit();
        }

        return $message;
    }

    /** @param  list<UploadedFile>  $files */
    public function fromStaff(Chat $chat, PlatformStaff $staff, ?string $body, array $files): ChatMessage
    {
        return DB::connection($chat->getConnectionName())->transaction(function () use ($chat, $staff, $body, $files) {
            $message = $chat->messages()->create([
                'sender' => ChatMessage::FROM_STAFF,
                'staff_id' => $staff->getKey(),
                'body' => $body,
                'attachments' => $this->store($chat, $files) ?: null,
            ]);
            $chat->forceFill([
                'status' => Chat::OPEN,
                'waiting' => false,
                'unread_staff' => 0,
                'unread_user' => $chat->unread_user + 1,
                'last_staff_id' => $staff->getKey(),
                'last_message_at' => now(),
            ])->save();
            $this->audit->record('support.chat_replied', $chat, new: ['files' => count($files)], tenantId: $chat->tenant_id, actor: $staff);

            return $message;
        });
    }

    /**
     * @param  list<UploadedFile>  $files
     * @return list<array{path: string, name: string, mime: string, size: int}>
     */
    private function store(Chat $chat, array $files): array
    {
        $out = [];
        foreach ($files as $file) {
            $path = $file->storeAs($chat->folder(), Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: 'bin'), 'local');
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
     * A message as the chat shows it; `$fileUrl` builds each attachment's download link.
     *
     * @param  \Closure(ChatMessage, int): string  $fileUrl
     * @return array<string, mixed>
     */
    public static function present(ChatMessage $m, \Closure $fileUrl): array
    {
        return [
            'id' => $m->id,
            'sender' => $m->sender,
            'staff' => $m->sender === ChatMessage::FROM_STAFF ? ($m->staff?->name ?? 'NewTech') : null,
            'body' => $m->body,
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

    /** Send one attachment (inline for an image, as a download otherwise). */
    public static function download(ChatMessage $message, int $index): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $file = ($message->attachments ?? [])[$index] ?? abort(404);
        abort_unless(Storage::disk('local')->exists($file['path']), 404);
        $inline = str_starts_with($file['mime'], 'image/') || $file['mime'] === 'application/pdf';

        return Storage::disk('local')->response($file['path'], $file['name'], ['Content-Type' => $file['mime']], $inline ? 'inline' : 'attachment');
    }
}
