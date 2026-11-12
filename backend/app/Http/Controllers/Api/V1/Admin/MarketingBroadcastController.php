<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\MarketingBroadcast;
use App\Models\MarketingCampaign;
use App\Models\NotificationGroup;
use App\Platform\Telegram\Telegram;
use App\Support\Authorization\Permissions;
use App\Support\Query\ApiQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Marketing > Broadcast: send a promotion to the school's Telegram groups
 * (linked under Settings > Telegram groups, same bot), and keep the history
 * with how each group went. broadcasts.view / create (send) / delete.
 */
final class MarketingBroadcastController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Telegram $telegram,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->allowed($request, Permissions::BROADCASTS_VIEW);

        $query = MarketingBroadcast::query()->with('campaign:id,code,name');
        $term = trim((string) $request->input('search', ''));
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(fn ($q) => $q->where('title', 'ILIKE', $like)->orWhere('message', 'ILIKE', $like));
        }

        $rows = ApiQuery::for($query, $request)
            ->filterable(['status', 'marketing_campaign_id'])
            ->sortable(['sent_at'], default: '-sent_at')
            ->paginate();

        return ApiResponse::success($rows->through(fn (MarketingBroadcast $b) => $this->row($b)));
    }

    /** The school's linked Telegram groups to send to, whether the bot is set up, and campaigns to tag. */
    public function groups(Request $request): JsonResponse
    {
        $this->allowed($request, Permissions::BROADCASTS_VIEW);

        return ApiResponse::success([
            'bot_ready' => $this->telegram->token() !== null,
            'groups' => $this->linkedGroups()->map(fn (NotificationGroup $g) => ['id' => $g->id, 'name' => $g->name, 'telegram_title' => $g->telegram_title])->values(),
            // Planned or running campaigns, to tag the broadcast with.
            'campaigns' => MarketingCampaign::query()->whereIn('status', ['planned', 'active'])->orderByDesc('start_on')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->allowed($request, Permissions::BROADCASTS_CREATE);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:4000'],
            'marketing_campaign_id' => ['nullable', 'integer', Rule::exists('tenant.marketing_campaigns', 'id')],
            'group_ids' => ['required', 'array', 'min:1'],
            'group_ids.*' => ['integer', 'distinct'],
        ]);

        $groups = $this->linkedGroups()->whereIn('id', $data['group_ids']);
        if ($groups->count() !== count($data['group_ids'])) {
            throw ValidationException::withMessages(['group_ids' => __('Pick linked Telegram groups of this school only.')]);
        }
        if ($this->telegram->token() === null) {
            throw ValidationException::withMessages(['group_ids' => __('The Telegram bot is not set up yet. Ask NewTech support.')]);
        }

        $results = [];
        foreach ($groups as $group) {
            $sent = $this->telegram->send((string) $group->telegram_chat_id, $data['message']);
            $results[] = ['group_id' => $group->id, 'group_name' => $group->name, 'ok' => $sent['ok'], 'message' => $sent['ok'] ? null : $sent['message']];
        }
        $ok = count(array_filter($results, fn ($r) => $r['ok']));

        $broadcast = MarketingBroadcast::query()->create([
            'title' => $data['title'],
            'message' => $data['message'],
            'marketing_campaign_id' => $data['marketing_campaign_id'] ?? null,
            'status' => $ok === count($results) ? 'sent' : ($ok > 0 ? 'partial' : 'failed'),
            'results' => $results,
            'sent_at' => now(),
            'created_by' => $request->user()?->id,
            'created_by_name' => $request->user()?->name,
        ]);

        return ApiResponse::created($this->row($broadcast->load('campaign:id,code,name')));
    }

    /** Removes it from the history only; Telegram messages already sent stay. */
    public function destroy(Request $request, MarketingBroadcast $broadcast): JsonResponse
    {
        $this->allowed($request, Permissions::BROADCASTS_DELETE);
        $broadcast->delete();

        return ApiResponse::noContent();
    }

    /** @return \Illuminate\Support\Collection<int, NotificationGroup> */
    private function linkedGroups()
    {
        return NotificationGroup::query()
            ->where('tenant_id', (int) $this->context->getOrFail()->getKey())
            ->whereNotNull('telegram_chat_id')
            ->orderBy('name')
            ->get();
    }

    private function allowed(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission) === true, 403);
    }

    /** @return array<string, mixed> */
    private function row(MarketingBroadcast $b): array
    {
        return [
            'id' => $b->id,
            'title' => $b->title,
            'message' => $b->message,
            'status' => $b->status,
            'results' => $b->results,
            'campaign' => $b->campaign ? ['id' => $b->campaign->id, 'code' => $b->campaign->code, 'name' => $b->campaign->name] : null,
            'marketing_campaign_id' => $b->marketing_campaign_id,
            'sent_at' => $b->sent_at?->toIso8601String(),
            'created_by_name' => $b->created_by_name,
        ];
    }
}
