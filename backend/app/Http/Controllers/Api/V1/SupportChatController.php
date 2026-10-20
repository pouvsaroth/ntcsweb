<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Role;
use App\Models\User;
use App\Platform\Models\ChatMessage;
use App\Platform\Support\Chats;
use App\Platform\Support\SupportViewSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The support chat button in the system (docs/backoffice/stage-10-support-chat.md):
 * a customer's staff user and NewTech support. Not for students, and not
 * for platform accounts with no school. A back office support view is
 * read-only, so it cannot send (GuardSupportView).
 */
final class SupportChatController extends Controller
{
    public function __construct(private readonly Chats $chats) {}

    /** The conversation: the last 100 messages, or only those after `after`. */
    public function show(Request $request): JsonResponse
    {
        // bo_* tables are UTC; this request runs in the school's zone.
        return SupportViewSession::inUtc(function () use ($request): JsonResponse {
            $user = $this->chatter($request);
            $chat = $this->chats->forUser($user);
            $after = (int) $request->query('after', 0);

            $messages = $chat->messages()->with('staff:id,name')
                ->when($after > 0, fn ($q) => $q->where('id', '>', $after))
                ->orderByDesc('id')->limit(100)->get()->reverse()->values();

            return ApiResponse::success([
                'chat' => ['id' => $chat->id, 'status' => $chat->status, 'unread' => $chat->unread_user],
                'messages' => $messages->map(fn (ChatMessage $m) => Chats::present($m, fn (ChatMessage $msg, int $i) => "/api/v1/support-chat/files/{$msg->id}/{$i}"))->values(),
            ]);
        });
    }

    /** For the button's dot: replies from NewTech not read yet. */
    public function unread(Request $request): JsonResponse
    {
        // bo_* tables are UTC; this request runs in the school's zone.
        return SupportViewSession::inUtc(function () use ($request): JsonResponse {
            $user = $this->chatter($request);
            $unread = (int) \App\Platform\Models\Chat::query()->where('user_id', $user->getKey())->value('unread_user');

            return ApiResponse::success(['unread' => $unread]);
        });
    }

    public function send(Request $request): JsonResponse
    {
        // bo_* tables are UTC; this request runs in the school's zone.
        return SupportViewSession::inUtc(function () use ($request): JsonResponse {
            $user = $this->chatter($request);
            $data = $request->validate([
                'body' => ['nullable', 'string', 'max:4000'],
                'files' => ['nullable', 'array', 'max:'.Chats::MAX_FILES],
                'files.*' => Chats::FILE_RULES,
            ]);
            $body = isset($data['body']) ? (trim($data['body']) ?: null) : null;
            $files = $request->file('files', []);
            if ($body === null && $files === []) {
                throw ValidationException::withMessages(['body' => __('Write a message or attach a file.')]);
            }

            $message = $this->chats->fromUser($user, $body, $files);

            return ApiResponse::created(Chats::present($message, fn (ChatMessage $msg, int $i) => "/api/v1/support-chat/files/{$msg->id}/{$i}"));
        });
    }

    /** The user saw NewTech's replies. */
    public function read(Request $request): JsonResponse
    {
        // bo_* tables are UTC; this request runs in the school's zone.
        return SupportViewSession::inUtc(function () use ($request): JsonResponse {
            $user = $this->chatter($request);
            \App\Platform\Models\Chat::query()->where('user_id', $user->getKey())->update(['unread_user' => 0]);

            return ApiResponse::success(['unread' => 0]);
        });
    }

    public function file(Request $request, int $message, int $index): StreamedResponse
    {
        $user = $this->chatter($request);
        $msg = ChatMessage::query()->with('chat')->findOrFail($message);
        abort_unless($msg->chat?->user_id === $user->getKey(), 404);

        return Chats::download($msg, $index);
    }

    /** A school's staff user — not a student, not a platform account. */
    private function chatter(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_if($user->tenant_id === null || $user->hasRole(Role::STUDENT), 403, __('The support chat is for staff.'));

        return $user;
    }
}
