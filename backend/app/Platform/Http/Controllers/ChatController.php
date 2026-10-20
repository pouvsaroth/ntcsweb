<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Platform\Jobs\SendChatTelegram;
use App\Platform\Models\Chat;
use App\Platform\Models\ChatMessage;
use App\Platform\Models\SupportSettings;
use App\Platform\Support\Chats;
use App\Platform\Support\PlatformAudit;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Support → Chats in the back office (docs/backoffice/stage-10-support-chat.md):
 * customers' questions, NewTech's answers, and the Telegram alert settings.
 */
final class ChatController extends Controller
{
    public function __construct(
        private readonly Chats $chats,
        private readonly PlatformAudit $audit,
    ) {}

    /** Chats, newest first; `filter[status]=open|closed`, `filter[waiting]=1`; search by person or customer. */
    public function index(Request $request): JsonResponse
    {
        $query = Chat::query()->with(['tenant:id,name,slug', 'lastStaff:id,name'])
            ->when($request->input('filter.waiting') === '1', fn ($q) => $q->where('waiting', true)->where('status', Chat::OPEN))
            ->when(in_array($request->input('filter.status'), [Chat::OPEN, Chat::CLOSED], true), fn ($q) => $q->where('status', $request->input('filter.status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.mb_strtolower((string) $request->input('search')).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(user_name) like ?', [$term])->orWhereRaw('lower(user_email) like ?', [$term])
                    ->orWhereHas('tenant', fn ($t) => $t->whereRaw('lower(name) like ?', [$term])));
            });

        $page = ApiQuery::for($query, $request)->sortable(['last_message_at'], default: '-last_message_at')->paginate();
        $last = ChatMessage::query()->whereIn('id', ChatMessage::query()->selectRaw('max(id)')->whereIn('chat_id', $page->getCollection()->modelKeys())->groupBy('chat_id'))
            ->get()->keyBy('chat_id');
        $page->through(fn (Chat $c) => $this->summary($c, $last->get($c->id)));

        return ApiResponse::success($page);
    }

    /** For the menu badge: customers waiting for an answer. */
    public function waiting(): JsonResponse
    {
        return ApiResponse::success(['waiting' => Chat::query()->where('status', Chat::OPEN)->where('waiting', true)->count()]);
    }

    /** One chat; opening it marks the customer's messages read. `after` = only newer messages. */
    public function show(Request $request, Chat $chat): JsonResponse
    {
        $after = (int) $request->query('after', 0);
        $messages = $chat->messages()->with('staff:id,name')
            ->when($after > 0, fn ($q) => $q->where('id', '>', $after))
            ->orderByDesc('id')->limit(200)->get()->reverse()->values();
        if ($chat->unread_staff > 0) {
            $chat->forceFill(['unread_staff' => 0])->save();
        }

        return ApiResponse::success([
            'chat' => $this->summary($chat->load(['tenant:id,name,slug', 'lastStaff:id,name']), null),
            'messages' => $messages->map(fn (ChatMessage $m) => Chats::present($m, fn (ChatMessage $msg, int $i) => "/api/v1/platform/support/chats/{$chat->id}/files/{$msg->id}/{$i}"))->values(),
        ]);
    }

    public function reply(Request $request, Chat $chat): JsonResponse
    {
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

        $message = $this->chats->fromStaff($chat, $request->user('staff'), $body, $files);

        return ApiResponse::created(Chats::present($message->load('staff:id,name'), fn (ChatMessage $msg, int $i) => "/api/v1/platform/support/chats/{$chat->id}/files/{$msg->id}/{$i}"));
    }

    public function close(Request $request, Chat $chat): JsonResponse
    {
        $chat->forceFill(['status' => Chat::CLOSED, 'waiting' => false, 'unread_staff' => 0])->save();
        $this->audit->record('support.chat_closed', $chat, tenantId: $chat->tenant_id);

        return ApiResponse::success($this->summary($chat->load(['tenant:id,name,slug', 'lastStaff:id,name']), null));
    }

    public function reopen(Chat $chat): JsonResponse
    {
        $chat->forceFill(['status' => Chat::OPEN])->save();
        $this->audit->record('support.chat_reopened', $chat, tenantId: $chat->tenant_id);

        return ApiResponse::success($this->summary($chat->load(['tenant:id,name,slug', 'lastStaff:id,name']), null));
    }

    public function file(Chat $chat, int $message, int $index): StreamedResponse
    {
        $msg = ChatMessage::query()->where('chat_id', $chat->id)->findOrFail($message);

        return Chats::download($msg, $index);
    }

    /** Telegram alert settings; the token is never sent back. */
    public function settings(): JsonResponse
    {
        return ApiResponse::success($this->presentSettings(SupportSettings::current()));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'telegram_bot_token' => ['nullable', 'string', 'max:200'],
            'telegram_chat_id' => ['nullable', 'string', 'max:50', 'regex:/^-?\d+$|^@[A-Za-z0-9_]{5,}$/'],
            'clear_token' => ['sometimes', 'boolean'],
        ]);
        $settings = SupportSettings::current();
        $changes = ['telegram_chat_id' => $data['telegram_chat_id'] ?? null];
        if (filled($data['telegram_bot_token'] ?? null)) {
            $changes['telegram_bot_token'] = trim($data['telegram_bot_token']);
        } elseif ($request->boolean('clear_token')) {
            $changes['telegram_bot_token'] = null;
        }
        $settings->update($changes);
        $this->audit->record('support.chat_settings_updated', $settings, new: ['telegram_chat_id' => $settings->telegram_chat_id, 'token_set' => filled($settings->telegram_bot_token)]);

        return ApiResponse::success($this->presentSettings($settings->fresh()));
    }

    public function testTelegram(): JsonResponse
    {
        $settings = SupportSettings::current();
        if (blank($settings->telegram_bot_token) || blank($settings->telegram_chat_id)) {
            return ApiResponse::success(['ok' => false, 'message' => __('Save the bot token and the group chat id first.')]);
        }
        try {
            return ApiResponse::success(SendChatTelegram::send($settings->telegram_bot_token, $settings->telegram_chat_id, '✅ NewTech support chat: Telegram alerts work.'));
        } catch (\Throwable $e) {
            return ApiResponse::success(['ok' => false, 'message' => $e->getMessage()]);
        }
    }

    /** @return array<string, mixed> */
    private function presentSettings(SupportSettings $s): array
    {
        return ['telegram_chat_id' => $s->telegram_chat_id, 'telegram_token_set' => filled($s->telegram_bot_token)];
    }

    /** @return array<string, mixed> */
    private function summary(Chat $c, ?ChatMessage $last): array
    {
        return [
            'id' => $c->id,
            'customer' => $c->tenant?->only(['id', 'name', 'slug']),
            'user_name' => $c->user_name,
            'user_email' => $c->user_email,
            'status' => $c->status,
            'waiting' => $c->waiting,
            'unread' => $c->unread_staff,
            'last_staff' => $c->lastStaff?->name,
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            'last_message' => $last ? [
                'sender' => $last->sender,
                'text' => \Illuminate\Support\Str::limit((string) $last->body, 120) ?: (count($last->attachments ?? []) ? '📎' : ''),
            ] : null,
        ];
    }
}
