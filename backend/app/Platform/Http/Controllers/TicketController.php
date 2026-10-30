<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Platform\Models\PlatformStaff;
use App\Platform\Models\Ticket;
use App\Platform\Models\TicketMessage;
use App\Platform\Support\Chats;
use App\Platform\Support\PlatformAudit;
use App\Platform\Support\Tickets;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Support → Tickets in the back office (docs/backoffice/stage-13-help-support.md, 13b):
 * every school's tickets; NewTech replies, assigns, sets status and priority.
 */
final class TicketController extends Controller
{
    public function __construct(
        private readonly Tickets $tickets,
        private readonly PlatformAudit $audit,
    ) {}

    /**
     * `filter[status]` = active (open + in progress), waiting (new customer
     * words), or one status; `filter[priority]`; `filter[mine]=1` = assigned
     * to me; search by number, subject, person or school.
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->input('filter.status');
        $query = Ticket::query()->with(['tenant:id,name,slug', 'assignee:id,name'])
            ->when($status === 'active', fn ($q) => $q->whereIn('status', [Ticket::OPEN, Ticket::IN_PROGRESS]))
            ->when($status === 'waiting', fn ($q) => $q->where('unread_staff', '>', 0))
            ->when(in_array($status, Ticket::STATUSES, true), fn ($q) => $q->where('status', $status))
            ->when(in_array($request->input('filter.priority'), Ticket::PRIORITIES, true), fn ($q) => $q->where('priority', $request->input('filter.priority')))
            ->when($request->input('filter.mine') === '1', fn ($q) => $q->where('assigned_staff_id', $request->user('staff')->getKey()))
            ->when($request->filled('search'), function ($q) use ($request) {
                $raw = trim((string) $request->input('search'));
                $term = '%'.mb_strtolower($raw).'%';
                $number = preg_match('/^(?:t-?)?0*(\d+)$/i', $raw, $m) ? (int) $m[1] : null;
                $q->where(fn ($w) => $w->whereRaw('lower(subject) like ?', [$term])
                    ->orWhereRaw('lower(user_name) like ?', [$term])
                    ->orWhereRaw('lower(user_email) like ?', [$term])
                    ->orWhereHas('tenant', fn ($t) => $t->whereRaw('lower(name) like ?', [$term]))
                    ->when($number !== null, fn ($n) => $n->orWhere('id', $number)));
            });

        $page = ApiQuery::for($query, $request)->sortable(['last_reply_at', 'created_at'], default: '-last_reply_at')->paginate();
        $page->through(fn (Ticket $t) => Tickets::summary($t, true));

        return ApiResponse::success($page);
    }

    /** For the menu badge: tickets with customer words NewTech has not read. */
    public function waiting(): JsonResponse
    {
        return ApiResponse::success(['waiting' => Ticket::query()->where('unread_staff', '>', 0)->count()]);
    }

    /** Who a ticket can be given to: active staff who may answer tickets. */
    public function staff(): JsonResponse
    {
        $staff = PlatformStaff::query()->with('roles')->where('status', PlatformStaff::STATUS_ACTIVE)->orderBy('name')->get()
            ->filter(fn (PlatformStaff $s) => $s->hasPermission('platform.support.tickets'))
            ->map(fn (PlatformStaff $s) => ['id' => $s->id, 'name' => $s->name])->values();

        return ApiResponse::success($staff);
    }

    /** One ticket; opening it reads the customer's words. */
    public function show(Ticket $ticket): JsonResponse
    {
        if ($ticket->unread_staff > 0) {
            $ticket->forceFill(['unread_staff' => 0])->save();
        }

        return ApiResponse::success($this->full($ticket));
    }

    public function reply(Request $request, Ticket $ticket): JsonResponse
    {
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

        $this->tickets->fromStaff($ticket, $request->user('staff'), $body, $files);

        return ApiResponse::created($this->full($ticket->fresh()));
    }

    /** Status, priority and who has it; any of the three. */
    public function update(Request $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(Ticket::STATUSES)],
            'priority' => ['sometimes', Rule::in(Ticket::PRIORITIES)],
            'assigned_staff_id' => ['sometimes', 'nullable', 'integer', Rule::exists(PlatformStaff::class, 'id')->where('status', PlatformStaff::STATUS_ACTIVE)],
        ]);
        $old = $ticket->only(['status', 'priority', 'assigned_staff_id']);

        if (isset($data['status'])) {
            $this->tickets->setStatus($ticket, $data['status'], $request->user('staff'));
        }
        $rest = array_intersect_key($data, array_flip(['priority', 'assigned_staff_id']));
        if ($rest !== []) {
            $ticket->forceFill($rest)->save();
        }
        $new = $ticket->fresh()->only(['status', 'priority', 'assigned_staff_id']);
        if ($new !== $old) {
            $this->audit->record('support.ticket_updated', $ticket, old: $old, new: $new, tenantId: $ticket->tenant_id);
        }

        return ApiResponse::success($this->full($ticket->fresh()));
    }

    public function file(Ticket $ticket, int $message, int $index): StreamedResponse
    {
        $msg = TicketMessage::query()->where('ticket_id', $ticket->id)->findOrFail($message);

        return Tickets::download($msg, $index);
    }

    /** @return array{ticket: array<string, mixed>, messages: list<array<string, mixed>>} */
    private function full(Ticket $ticket): array
    {
        $ticket->load(['tenant:id,name,slug', 'assignee:id,name']);
        $messages = $ticket->messages()->with('staff:id,name')->orderBy('id')->get();

        return [
            'ticket' => Tickets::summary($ticket, true),
            'messages' => $messages->map(fn (TicketMessage $m) => Tickets::present($m, fn (TicketMessage $msg, int $i) => "/api/v1/platform/support/tickets/{$ticket->id}/files/{$msg->id}/{$i}"))->values()->all(),
        ];
    }
}
