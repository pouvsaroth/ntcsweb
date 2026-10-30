<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Coaching;
use App\Models\CoachingSession;
use App\Models\Staff;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\ProfessionalDevelopment\ProfessionalDevelopmentAccess;
use App\Support\Notifications\NotificationType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * HRM > Professional Development > Coaching & mentoring
 * (docs/hrm/professional-development.md). A manager pairs a coach or mentor
 * with a staff member around a goal — often the next steps of a classroom
 * observation — and both are told. The coach logs sessions (topic, notes,
 * agreed actions) and closes it with an outcome; the person coached can
 * read it all.
 */
final class CoachingController extends Controller
{
    private const LINK = '/admin/professional-development/coaching';

    public function __construct(
        private readonly ProfessionalDevelopmentAccess $access,
        private readonly NotificationService $notifications,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->canUse($user), 403);
        $me = $this->access->staffId($user) ?? 0;

        $query = Coaching::query()->with(['coach', 'staff'])->withCount('sessions')->withMax('sessions', 'session_date');
        $mine = $request->query('mine');
        if ($mine === 'coach') {
            $query->where('coach_staff_id', $me);
        } elseif ($mine === 'coached') {
            $query->where('staff_id', $me);
        } elseif (! $this->access->canViewAll($user)) {
            $query->where(fn ($q) => $q->where('coach_staff_id', $me)->orWhere('staff_id', $me));
        }
        foreach (['status', 'type'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, (string) $request->query($field));
            }
        }
        if ($request->filled('staff_id')) {
            $id = $request->integer('staff_id');
            $query->where(fn ($q) => $q->where('staff_id', $id)->orWhere('coach_staff_id', $id));
        }
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $person = fn ($s) => $s->where('first_name', 'ilike', $like)->orWhere('last_name', 'ilike', $like)->orWhere('employee_code', 'ilike', $like);
            $query->where(fn ($q) => $q->where('goal', 'ilike', $like)->orWhereHas('staff', $person)->orWhereHas('coach', $person));
        }

        $rows = $query->orderByRaw("case status when 'active' then 0 else 1 end")->orderByDesc('start_date')->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 15), 1), 100));
        $rows->setCollection($rows->getCollection()->map(fn (Coaching $c) => $this->row($c, $user)));

        return ApiResponse::success($rows);
    }

    public function summary(Request $request): JsonResponse
    {
        abort_unless($this->access->canViewAll($request->user()), 403);

        return ApiResponse::success([
            'active' => Coaching::query()->where('status', 'active')->count(),
            'completed_this_year' => Coaching::query()->where('status', 'completed')->whereYear('start_date', now()->year)->count(),
            'sessions_this_month' => CoachingSession::query()->whereBetween('session_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->count(),
            'coaches' => Coaching::query()->where('status', 'active')->distinct()->count('coach_staff_id'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->canManage($user), 403);
        $data = $this->validated($request, true);

        $coaching = Coaching::query()->create([...$data, 'status' => 'active', 'created_by' => $user->getKey()]);

        $this->notify([(int) $coaching->coach_staff_id, (int) $coaching->staff_id], $coaching, $user);

        return ApiResponse::created($this->detail($coaching, $user));
    }

    public function show(Request $request, Coaching $coaching): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->canSee($coaching, $user), 404);

        return ApiResponse::success($this->detail($coaching, $user));
    }

    /** A manager changes anything; the coach may close it (status and outcome). */
    public function update(Request $request, Coaching $coaching): JsonResponse
    {
        $user = $request->user();
        if ($this->access->canManage($user)) {
            $data = $this->validated($request, false);
        } else {
            abort_unless($this->isCoach($coaching, $user), 403);
            $data = $request->validate([
                'status' => ['sometimes', Rule::in(Coaching::STATUSES)],
                'outcome' => ['nullable', 'string', 'max:10000'],
            ]);
        }

        $coaching->update($data);

        return ApiResponse::success($this->detail($coaching, $user));
    }

    public function destroy(Request $request, Coaching $coaching): JsonResponse
    {
        abort_unless($this->access->canManage($request->user()), 403);

        $coaching->delete();

        return ApiResponse::noContent();
    }

    public function storeSession(Request $request, Coaching $coaching): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->canWriteSessions($coaching, $user), 403);

        $coaching->sessions()->create([...$this->validatedSession($request), 'created_by' => $user->getKey()]);

        return ApiResponse::created($this->detail($coaching, $user));
    }

    public function updateSession(Request $request, CoachingSession $session): JsonResponse
    {
        $user = $request->user();
        $coaching = $session->coaching()->firstOrFail();
        abort_unless($this->canWriteSessions($coaching, $user), 403);

        $session->update($this->validatedSession($request));

        return ApiResponse::success($this->detail($coaching, $user));
    }

    public function destroySession(Request $request, CoachingSession $session): JsonResponse
    {
        $user = $request->user();
        $coaching = $session->coaching()->firstOrFail();
        abort_unless($this->canWriteSessions($coaching, $user), 403);

        $session->delete();

        return ApiResponse::success($this->detail($coaching, $user));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $staff = Rule::exists('tenant.staff', 'id')->whereNull('deleted_at');

        return $request->validate([
            'type' => ['sometimes', Rule::in(Coaching::TYPES)],
            'coach_staff_id' => [$required, 'integer', $staff],
            'staff_id' => [$required, 'integer', 'different:coach_staff_id', $staff],
            'classroom_observation_id' => ['nullable', 'integer', Rule::exists('tenant.classroom_observations', 'id')],
            'goal' => [$required, 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:10000'],
            'start_date' => [$required, 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'status' => ['sometimes', Rule::in(Coaching::STATUSES)],
            'outcome' => ['nullable', 'string', 'max:10000'],
        ]);
    }

    /** @return array<string, mixed> */
    private function validatedSession(Request $request): array
    {
        return $request->validate([
            'session_date' => ['required', 'date_format:Y-m-d'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'topic' => ['required', 'string', 'max:300'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'action_items' => ['nullable', 'string', 'max:10000'],
        ]);
    }

    private function isCoach(Coaching $coaching, User $user): bool
    {
        $me = $this->access->staffId($user);

        return $me !== null && $me === (int) $coaching->coach_staff_id && $this->access->canUse($user);
    }

    private function canWriteSessions(Coaching $coaching, User $user): bool
    {
        return $this->access->canManage($user) || $this->isCoach($coaching, $user);
    }

    private function canSee(Coaching $coaching, User $user): bool
    {
        if ($this->access->canViewAll($user)) {
            return true;
        }
        $me = $this->access->staffId($user);

        return $this->access->canUse($user) && $me !== null && ($me === (int) $coaching->coach_staff_id || $me === (int) $coaching->staff_id);
    }

    /** @return array<string, mixed> */
    private function detail(Coaching $coaching, User $user): array
    {
        $coaching->load(['coach', 'staff', 'observation:id,observed_on,form_name,status', 'sessions' => fn ($q) => $q->orderByDesc('session_date')->orderByDesc('id')])
            ->loadCount('sessions');

        return [
            ...$this->row($coaching, $user),
            'observation' => $coaching->observation ? [
                'id' => $coaching->observation->id,
                'observed_on' => $coaching->observation->observed_on?->toDateString(),
                'form_name' => $coaching->observation->form_name,
            ] : null,
            'sessions' => $coaching->sessions->map(fn (CoachingSession $s) => [
                'id' => $s->id,
                'session_date' => $s->session_date?->toDateString(),
                'duration_minutes' => $s->duration_minutes,
                'topic' => $s->topic,
                'notes' => $s->notes,
                'action_items' => $s->action_items,
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function row(Coaching $c, User $user): array
    {
        $person = fn (?Staff $s) => $s ? ['id' => $s->id, 'name' => $s->fullName(), 'employee_code' => $s->employee_code] : null;
        // From the list query's withMax(), or from the sessions loaded for the detail view.
        $lastSession = $c->getAttributes()['sessions_max_session_date']
            ?? ($c->relationLoaded('sessions') ? $c->sessions->max('session_date')?->toDateString() : null);

        return [
            ...Arr::only($c->toArray(), ['id', 'type', 'coach_staff_id', 'staff_id', 'classroom_observation_id', 'goal', 'description', 'status', 'outcome']),
            'start_date' => $c->start_date?->toDateString(),
            'end_date' => $c->end_date?->toDateString(),
            'coach' => $person($c->coach),
            'staff' => $person($c->staff),
            'sessions_count' => (int) ($c->sessions_count ?? 0),
            'last_session_date' => $lastSession ? substr((string) $lastSession, 0, 10) : null,
            'can' => [
                'manage' => $this->access->canManage($user),
                'sessions' => $this->canWriteSessions($c, $user),
                'close' => $this->access->canManage($user) || $this->isCoach($c, $user),
            ],
        ];
    }

    /** @param  list<int>  $staffIds */
    private function notify(array $staffIds, Coaching $coaching, User $actor): void
    {
        $users = Staff::query()->whereIn('id', $staffIds)->with('user')->get()->pluck('user')->filter()
            ->reject(fn (User $u) => $u->getKey() === $actor->getKey());
        if ($users->isEmpty()) {
            return;
        }
        $coaching->loadMissing(['coach', 'staff']);

        $this->notifications->notifyMany($users, NotificationType::COACHING_ASSIGNED, [
            'coaching_id' => $coaching->id,
            'type' => $coaching->type,
            'coach_name' => $coaching->coach?->fullName(),
            'staff_name' => $coaching->staff?->fullName(),
            'goal' => $coaching->goal,
        ], link: self::LINK);
    }
}
