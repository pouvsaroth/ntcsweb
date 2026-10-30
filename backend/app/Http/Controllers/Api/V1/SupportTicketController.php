<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Role;
use App\Models\User;
use App\Platform\Billing\Modules;
use App\Platform\Billing\SchoolModules;
use App\Platform\Models\Ticket;
use App\Platform\Models\TicketMessage;
use App\Platform\Support\Chats;
use App\Platform\Support\SupportViewSession;
use App\Platform\Support\Tickets;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Help & Support → Support tickets (docs/backoffice/stage-13-help-support.md, 13b).
 * Each user sees and answers only their own tickets. Needs help-support.view;
 * not for students. A back office support view is read-only (GuardSupportView)
 * and opening a ticket there does not mark NewTech's replies read.
 */
final class SupportTicketController extends Controller
{
    public function __construct(
        private readonly Tickets $tickets,
        private readonly SchoolModules $modules,
        private readonly SupportViewSession $views,
    ) {}

    /** The user's tickets, latest activity first; `status=active|done` narrows them (active also keeps unread ones). */
    public function index(Request $request): JsonResponse
    {
        return SupportViewSession::inUtc(function () use ($request): JsonResponse {
            $user = $this->requester($request);
            $tickets = Ticket::query()->where('user_id', $user->getKey())
                // Active = still being worked on, or with NewTech news not seen yet.
                ->when($request->query('status') === 'active', fn ($q) => $q->where(fn ($w) => $w->whereIn('status', [Ticket::OPEN, Ticket::IN_PROGRESS])->orWhere('unread_user', '>', 0)))
                ->when($request->query('status') === 'done', fn ($q) => $q->whereIn('status', [Ticket::RESOLVED, Ticket::CLOSED]))
                ->orderByDesc('last_reply_at')->orderByDesc('id')
                ->limit(200)->get();

            return ApiResponse::success($tickets->map(fn (Ticket $t) => Tickets::summary($t, false))->values());
        });
    }

    /** Replies and changes from NewTech not seen yet (for the Support button's dot). */
    public function unread(Request $request): JsonResponse
    {
        $user = $this->requester($request);

        return ApiResponse::success(['unread' => (int) Ticket::query()->where('user_id', $user->getKey())->sum('unread_user')]);
    }

    /** What the New ticket form offers: the modules on the school's plan and the priorities. */
    public function options(Request $request): JsonResponse
    {
        $user = $this->requester($request);
        $catalog = Modules::catalog();
        $enabled = $this->modules->enabled($user->tenant_id) ?? array_keys($catalog);

        return ApiResponse::success([
            'modules' => collect($enabled)->filter(fn (string $code) => isset($catalog[$code]))
                ->map(fn (string $code) => ['code' => $code, 'label' => $catalog[$code]['label'], 'label_km' => $catalog[$code]['label_km'] ?? null])->values(),
            'priorities' => Ticket::PRIORITIES,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return SupportViewSession::inUtc(function () use ($request): JsonResponse {
            $user = $this->requester($request);
            $enabled = $this->modules->enabled($user->tenant_id) ?? array_keys(Modules::catalog());
            $data = $request->validate([
                'subject' => ['required', 'string', 'max:200'],
                'module' => ['nullable', 'string', Rule::in($enabled)],
                'priority' => ['required', Rule::in(Ticket::PRIORITIES)],
                'body' => ['required', 'string', 'max:8000'],
                'files' => ['nullable', 'array', 'max:'.Chats::MAX_FILES],
                'files.*' => Chats::FILE_RULES,
            ]);

            $ticket = $this->tickets->open($user, [
                'subject' => trim($data['subject']),
                'module' => $data['module'] ?? null,
                'priority' => $data['priority'],
                'body' => trim($data['body']),
            ], $request->file('files', []));

            return ApiResponse::created(Tickets::summary($ticket, false));
        });
    }

    /** One ticket and its thread; opening it reads NewTech's replies. */
    public function show(Request $request, int $ticket): JsonResponse
    {
        return SupportViewSession::inUtc(function () use ($request, $ticket): JsonResponse {
            $t = $this->own($request, $ticket);
            $messages = $t->messages()->with('staff:id,name')->orderBy('id')->get();
            if ($t->unread_user > 0 && $this->views->idFrom($request) === null) {
                $t->forceFill(['unread_user' => 0])->save();
            }

            return ApiResponse::success([
                'ticket' => Tickets::summary($t, false),
                'messages' => $messages->map(fn (TicketMessage $m) => Tickets::present($m, fn (TicketMessage $msg, int $i) => "/api/v1/support-tickets/{$t->id}/files/{$msg->id}/{$i}"))->values(),
            ]);
        });
    }

    public function reply(Request $request, int $ticket): JsonResponse
    {
        return SupportViewSession::inUtc(function () use ($request, $ticket): JsonResponse {
            $t = $this->own($request, $ticket);
            $data = $request->validate([
                'body' => ['nullable', 'string', 'max:8000'],
                'files' => ['nullable', 'array', 'max:'.Chats::MAX_FILES],
                'files.*' => Chats::FILE_RULES,
            ]);
            $body = isset($data['body']) ? (trim($data['body']) ?: null) : null;
            $files = $request->file('files', []);
            if ($body === null && $files === []) {
                throw ValidationException::withMessages(['body' => __('Write a message or attach a file.')]);
            }

            $this->tickets->fromUser($t, $body, $files);

            return $this->show($request, $ticket);
        });
    }

    /** The user's problem is solved: they close the ticket. */
    public function close(Request $request, int $ticket): JsonResponse
    {
        return SupportViewSession::inUtc(function () use ($request, $ticket): JsonResponse {
            $t = $this->own($request, $ticket);
            $this->tickets->setStatus($t, Ticket::CLOSED, null);

            return $this->show($request, $ticket);
        });
    }

    public function file(Request $request, int $ticket, int $message, int $index): StreamedResponse
    {
        $t = $this->own($request, $ticket);
        $msg = TicketMessage::query()->where('ticket_id', $t->id)->findOrFail($message);

        return Tickets::download($msg, $index);
    }

    private function own(Request $request, int $id): Ticket
    {
        $user = $this->requester($request);

        return Ticket::query()->where('user_id', $user->getKey())->findOrFail($id);
    }

    /** A school's staff user whose role has Help & Support. */
    private function requester(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_if($user->tenant_id === null || $user->hasRole(Role::STUDENT) || ! $user->hasPermission(Permissions::HELP_SUPPORT_VIEW), 403);

        return $user;
    }
}
