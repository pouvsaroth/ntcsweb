<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Models\School;
use App\Platform\Models\SupportSession;
use App\Platform\Models\SupportSettings;
use App\Platform\Support\PlatformAudit;
use App\Platform\Support\SchoolHealth;
use App\Platform\Support\SupportViews;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Support: one school's health (6a) and read-only support views (6b).
 * See docs/backoffice/stage-6-support.md.
 */
final class SupportController extends Controller
{
    public function __construct(
        private readonly SchoolHealth $health,
        private readonly SupportViews $views,
        private readonly PlatformAudit $audit,
    ) {}

    public function health(Tenant $school): JsonResponse
    {
        return ApiResponse::success($this->health->report($school));
    }

    /** Every support view, newest first; filter by school or open ones. */
    public function index(Request $request): JsonResponse
    {
        $query = SupportSession::query()
            ->with(['tenant:id,name,slug', 'staff:id,name', 'endedByStaff:id,name', 'user:id,name,email'])
            ->withCount('events')
            ->when($request->input('filter.tenant_id'), fn ($q, $id) => $q->where('tenant_id', (int) $id))
            ->when($request->input('filter.state') === 'open', fn ($q) => $q->whereNull('ended_at')->where('expires_at', '>', now()));

        $page = ApiQuery::for($query, $request)
            ->searchable('reason')
            ->sortable(['started_at'], default: '-started_at')
            ->paginate();

        $page->through(fn (SupportSession $s) => SupportViews::present($s));

        return ApiResponse::success($page);
    }

    public function show(SupportSession $session): JsonResponse
    {
        $session->load(['tenant:id,name,slug', 'staff:id,name', 'endedByStaff:id,name', 'user:id,name,email']);

        return ApiResponse::success(SupportViews::present($session, withEvents: true));
    }

    /** The school's active users to view as — the owner first. */
    public function users(Request $request, Tenant $school): JsonResponse
    {
        $ownerId = School::for($school)->owner_user_id;
        $search = trim((string) $request->input('search', ''));

        $users = User::query()->inTenant($school->getKey())
            ->where('status', User::STATUS_ACTIVE)
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'ilike', "%{$search}%")->orWhere('email', 'ilike', "%{$search}%")->orWhere('phone', 'ilike', "%{$search}%")))
            ->when($ownerId, fn ($q) => $q->orderByRaw('id = ? desc', [$ownerId]))
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'email', 'phone']);

        return ApiResponse::success($users->map(fn (User $u) => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone, 'is_owner' => $u->id === $ownerId,
        ])->values());
    }

    public function store(Request $request, Tenant $school): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $user = User::query()->withoutGlobalScopes()->find($data['user_id']);
        abort_if($user === null, 422, __('This user is not in this school.'));

        ['session' => $session, 'url' => $url] = $this->views->open($school, $user, trim($data['reason']), $request->user('staff'));
        $session->load(['tenant:id,name,slug', 'staff:id,name', 'user:id,name,email']);

        return ApiResponse::created(SupportViews::present($session) + ['url' => $url]);
    }

    public function end(Request $request, SupportSession $session): JsonResponse
    {
        $this->views->end($session, $request->user('staff'));
        $session->load(['tenant:id,name,slug', 'staff:id,name', 'endedByStaff:id,name', 'user:id,name,email']);

        return ApiResponse::success(SupportViews::present($session));
    }

    public function settings(): JsonResponse
    {
        return ApiResponse::success(SupportSettings::current()->only(['duration_minutes']));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['duration_minutes' => ['required', 'integer', 'between:5,240']]);

        $settings = SupportSettings::current();
        $old = $settings->only(['duration_minutes']);

        DB::connection($settings->getConnectionName())->transaction(function () use ($settings, $data, $old) {
            $settings->update($data);
            $this->audit->record('support.settings_updated', $settings, $old, $settings->only(['duration_minutes']));
        });

        return ApiResponse::success($settings->only(['duration_minutes']));
    }
}
