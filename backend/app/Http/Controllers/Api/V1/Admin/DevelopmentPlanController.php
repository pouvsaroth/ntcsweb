<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Coaching;
use App\Models\DevelopmentPlan;
use App\Models\DevelopmentPlanGoal;
use App\Models\Staff;
use App\Models\Training;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\ProfessionalDevelopment\ProfessionalDevelopmentAccess;
use App\Support\Notifications\NotificationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * HRM > Professional Development > Development plan
 * (docs/hrm/professional-development.md). A manager writes a staff member's
 * plan for a period: goals, how they'll get there, the support (optionally a
 * training or coaching) and target dates. It stays a draft until shared;
 * then the staff member and their reviewer are told. The staff member and
 * the reviewer update each goal's progress; the reviewer (or a manager)
 * writes the review and closes the plan.
 */
final class DevelopmentPlanController extends Controller
{
    private const LINK = '/admin/professional-development/plans';

    public function __construct(
        private readonly ProfessionalDevelopmentAccess $access,
        private readonly NotificationService $notifications,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->canUse($user), 403);
        $me = $this->access->staffId($user) ?? 0;

        $query = $this->withProgress(DevelopmentPlan::query()->with(['staff', 'reviewer']));
        $mine = $request->query('mine');
        if ($mine === 'own') {
            $query->where('staff_id', $me)->where('status', '!=', 'draft');
        } elseif ($mine === 'reviewing') {
            $query->where('reviewer_staff_id', $me)->where('status', '!=', 'draft');
        } elseif (! $this->access->canViewAll($user)) {
            $query->where('status', '!=', 'draft')->where(fn ($q) => $q->where('staff_id', $me)->orWhere('reviewer_staff_id', $me));
        }
        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }
        if ($request->filled('staff_id')) {
            $query->where('staff_id', $request->integer('staff_id'));
        }
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(fn ($q) => $q->where('title', 'ilike', $like)->orWhereHas('staff', fn ($s) => $s
                ->where('first_name', 'ilike', $like)->orWhere('last_name', 'ilike', $like)->orWhere('employee_code', 'ilike', $like)));
        }

        $rows = $query->orderByRaw("case status when 'active' then 0 when 'draft' then 1 else 2 end")->orderByDesc('start_date')->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 15), 1), 100));
        $rows->setCollection($rows->getCollection()->map(fn (DevelopmentPlan $p) => $this->row($p, $user)));

        return ApiResponse::success($rows);
    }

    public function summary(Request $request): JsonResponse
    {
        abort_unless($this->access->canViewAll($request->user()), 403);

        $activeGoals = DevelopmentPlanGoal::query()->whereHas('plan', fn ($q) => $q->where('status', 'active'))->where('status', '!=', 'dropped');
        $goalCount = (clone $activeGoals)->count();

        return ApiResponse::success([
            'active' => DevelopmentPlan::query()->where('status', 'active')->count(),
            'drafts' => DevelopmentPlan::query()->where('status', 'draft')->count(),
            'goals_done_percent' => $goalCount > 0 ? (int) round((clone $activeGoals)->where('status', 'done')->count() * 100 / $goalCount) : null,
            'overdue_goals' => (clone $activeGoals)->whereNotIn('status', ['done'])->whereNotNull('target_date')->where('target_date', '<', now()->toDateString())->count(),
        ]);
    }

    /** Trainings and (for one staff member) coachings a goal can point to. */
    public function options(Request $request): JsonResponse
    {
        abort_unless($this->access->canManage($request->user()), 403);

        $trainings = Training::query()->where('status', '!=', 'cancelled')
            ->where('start_date', '>=', now()->subYears(2)->toDateString())
            ->orderByDesc('start_date')->limit(300)->get(['id', 'title', 'start_date', 'status'])
            ->map(fn (Training $t) => ['id' => $t->id, 'title' => $t->title, 'start_date' => $t->start_date?->toDateString(), 'status' => $t->status]);

        $coachings = $request->filled('staff_id')
            ? Coaching::query()->where('staff_id', $request->integer('staff_id'))->where('status', '!=', 'cancelled')
                ->orderByDesc('start_date')->get(['id', 'goal', 'type', 'start_date', 'status'])
                ->map(fn (Coaching $c) => ['id' => $c->id, 'goal' => $c->goal, 'type' => $c->type, 'start_date' => $c->start_date?->toDateString(), 'status' => $c->status])
            : collect();

        return ApiResponse::success(['trainings' => $trainings->values(), 'coachings' => $coachings->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->canManage($user), 403);
        $data = $this->validated($request, true);

        $plan = DB::connection('tenant')->transaction(function () use ($data, $user) {
            $plan = DevelopmentPlan::query()->create([
                ...Arr::except($data, ['goals']),
                'status' => $data['status'] ?? 'draft',
                'created_by' => $user->getKey(),
            ]);
            $this->syncGoals($plan, $data['goals'] ?? []);

            return $plan;
        });

        if ($plan->status === 'active') {
            $this->notifyShared($plan, $user);
        }

        return ApiResponse::created($this->detail($plan, $user));
    }

    public function show(Request $request, DevelopmentPlan $plan): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->canSee($plan, $user), 404);

        return ApiResponse::success($this->detail($plan, $user));
    }

    /**
     * A manager changes anything (goals are replaced by the list sent, keeping
     * the ids it names). The reviewer may only write the review and close or
     * reopen the plan.
     */
    public function update(Request $request, DevelopmentPlan $plan): JsonResponse
    {
        $user = $request->user();
        $wasDraft = $plan->status === 'draft';

        if ($this->access->canManage($user)) {
            $data = $this->validated($request, false);
        } else {
            abort_unless($this->isReviewer($plan, $user) && $plan->status !== 'draft', 403);
            $data = $request->validate([
                'status' => ['sometimes', Rule::in(['active', 'completed'])],
                'review_note' => ['nullable', 'string', 'max:10000'],
            ]);
        }

        if (array_key_exists('status', $data) && $data['status'] !== $plan->status) {
            $data['completed_at'] = $data['status'] === 'completed' ? now() : null;
        }

        DB::connection('tenant')->transaction(function () use ($plan, $data) {
            $plan->update(Arr::except($data, ['goals']));
            if (array_key_exists('goals', $data)) {
                $this->syncGoals($plan, $data['goals']);
            }
        });

        if ($wasDraft && $plan->status === 'active') {
            $this->notifyShared($plan, $user);
        }

        return ApiResponse::success($this->detail($plan, $user));
    }

    public function destroy(Request $request, DevelopmentPlan $plan): JsonResponse
    {
        abort_unless($this->access->canManage($request->user()), 403);

        $plan->delete();

        return ApiResponse::noContent();
    }

    /** The staff member, the reviewer or a manager moves a goal along. */
    public function progress(Request $request, DevelopmentPlanGoal $goal): JsonResponse
    {
        $user = $request->user();
        $plan = $goal->plan()->firstOrFail();
        abort_unless($this->canUpdateProgress($plan, $user), 403);

        $data = $request->validate([
            'status' => ['required', Rule::in(DevelopmentPlanGoal::STATUSES)],
            'progress' => ['nullable', 'integer', 'min:0', 'max:100'],
            'staff_note' => ['nullable', 'string', 'max:10000'],
        ]);
        $data['progress'] = match ($data['status']) {
            'done' => 100,
            'not_started' => 0,
            default => (int) ($data['progress'] ?? $goal->progress),
        };

        $goal->update($data);

        return ApiResponse::success($this->detail($plan, $user));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $staff = Rule::exists('tenant.staff', 'id')->whereNull('deleted_at');

        return $request->validate([
            'staff_id' => [$required, 'integer', $staff],
            'reviewer_staff_id' => ['nullable', 'integer', 'different:staff_id', $staff],
            'title' => [$required, 'string', 'max:200'],
            'start_date' => [$required, 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'status' => ['sometimes', Rule::in(DevelopmentPlan::STATUSES)],
            'summary' => ['nullable', 'string', 'max:10000'],
            'review_note' => ['nullable', 'string', 'max:10000'],
            'goals' => ['sometimes', 'array', 'max:50'],
            'goals.*.id' => ['nullable', 'integer'],
            'goals.*.title' => ['required', 'string', 'max:300'],
            'goals.*.area' => ['nullable', 'string', 'max:120'],
            'goals.*.actions' => ['nullable', 'string', 'max:10000'],
            'goals.*.support' => ['nullable', 'string', 'max:10000'],
            'goals.*.training_id' => ['nullable', 'integer', Rule::exists('tenant.trainings', 'id')],
            'goals.*.coaching_id' => ['nullable', 'integer', Rule::exists('tenant.coachings', 'id')],
            'goals.*.target_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
    }

    /**
     * Replace the plan's goals with the list sent, in that order. A goal that
     * names one of this plan's ids keeps its progress; the rest are new.
     *
     * @param  list<array<string, mixed>>  $goals
     */
    private function syncGoals(DevelopmentPlan $plan, array $goals): void
    {
        $existing = $plan->goals()->get()->keyBy('id');
        $keep = [];
        foreach (array_values($goals) as $i => $g) {
            $fields = [
                ...Arr::only($g, ['title', 'area', 'actions', 'support', 'training_id', 'coaching_id', 'target_date']),
                'sort_order' => $i,
            ];
            $goal = isset($g['id']) ? $existing->get((int) $g['id']) : null;
            if ($goal) {
                $goal->update($fields);
            } else {
                $goal = $plan->goals()->create($fields);
            }
            $keep[] = $goal->id;
        }
        $plan->goals()->whereNotIn('id', $keep)->delete();
    }

    /** @param  Builder<DevelopmentPlan>  $query */
    private function withProgress(Builder $query): Builder
    {
        return $query
            ->withCount(['goals', 'goals as goals_done_count' => fn ($q) => $q->where('status', 'done')])
            ->withAvg(['goals as goals_progress_avg' => fn ($q) => $q->where('status', '!=', 'dropped')], 'progress');
    }

    private function isOwner(DevelopmentPlan $plan, User $user): bool
    {
        $me = $this->access->staffId($user);

        return $me !== null && $me === (int) $plan->staff_id && $this->access->canUse($user);
    }

    private function isReviewer(DevelopmentPlan $plan, User $user): bool
    {
        $me = $this->access->staffId($user);

        return $me !== null && $me === (int) $plan->reviewer_staff_id && $this->access->canUse($user);
    }

    private function canSee(DevelopmentPlan $plan, User $user): bool
    {
        return $this->access->canViewAll($user)
            || ($plan->status !== 'draft' && ($this->isOwner($plan, $user) || $this->isReviewer($plan, $user)));
    }

    private function canUpdateProgress(DevelopmentPlan $plan, User $user): bool
    {
        return $plan->status === 'active'
            && ($this->access->canManage($user) || $this->isOwner($plan, $user) || $this->isReviewer($plan, $user));
    }

    /** @return array<string, mixed> */
    private function detail(DevelopmentPlan $plan, User $user): array
    {
        $plan = $this->withProgress(DevelopmentPlan::query()->whereKey($plan->getKey()))
            ->with(['staff', 'reviewer', 'goals' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')->with([
                'training:id,title,start_date,status',
                'coaching:id,goal,type,status',
            ])])
            ->firstOrFail();

        return [
            ...$this->row($plan, $user),
            'goals' => $plan->goals->map(fn (DevelopmentPlanGoal $g) => [
                ...Arr::only($g->toArray(), ['id', 'title', 'area', 'actions', 'support', 'training_id', 'coaching_id', 'status', 'progress', 'staff_note']),
                'target_date' => $g->target_date?->toDateString(),
                'overdue' => $plan->status === 'active' && $g->target_date !== null && $g->target_date->lt(today()) && ! in_array($g->status, ['done', 'dropped'], true),
                'training' => $g->training ? ['id' => $g->training->id, 'title' => $g->training->title, 'start_date' => $g->training->start_date?->toDateString(), 'status' => $g->training->status] : null,
                'coaching' => $g->coaching ? ['id' => $g->coaching->id, 'goal' => $g->coaching->goal, 'type' => $g->coaching->type, 'status' => $g->coaching->status] : null,
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function row(DevelopmentPlan $p, User $user): array
    {
        $person = fn (?Staff $s) => $s ? ['id' => $s->id, 'name' => $s->fullName(), 'employee_code' => $s->employee_code] : null;
        $attrs = $p->getAttributes();
        $avg = $attrs['goals_progress_avg'] ?? null;

        return [
            ...Arr::only($p->toArray(), ['id', 'staff_id', 'reviewer_staff_id', 'title', 'status', 'summary', 'review_note']),
            'start_date' => $p->start_date?->toDateString(),
            'end_date' => $p->end_date?->toDateString(),
            'completed_at' => $p->completed_at?->toIso8601String(),
            'staff' => $person($p->staff),
            'reviewer' => $person($p->reviewer),
            'goals_count' => (int) ($attrs['goals_count'] ?? 0),
            'goals_done' => (int) ($attrs['goals_done_count'] ?? 0),
            'progress' => $avg !== null ? (int) round((float) $avg) : 0,
            'can' => [
                'manage' => $this->access->canManage($user),
                'review' => $p->status !== 'draft' && ($this->access->canManage($user) || $this->isReviewer($p, $user)),
                'progress' => $this->canUpdateProgress($p, $user),
            ],
        ];
    }

    private function notifyShared(DevelopmentPlan $plan, User $actor): void
    {
        $ids = array_values(array_filter([(int) $plan->staff_id, $plan->reviewer_staff_id !== null ? (int) $plan->reviewer_staff_id : null]));
        $users = Staff::query()->whereIn('id', $ids)->with('user')->get()->pluck('user')->filter()
            ->reject(fn (User $u) => $u->getKey() === $actor->getKey());
        if ($users->isEmpty()) {
            return;
        }
        $plan->loadMissing('staff');

        $this->notifications->notifyMany($users, NotificationType::DEVELOPMENT_PLAN_SHARED, [
            'development_plan_id' => $plan->id,
            'title' => $plan->title,
            'staff_name' => $plan->staff?->fullName(),
        ], link: self::LINK);
    }
}
