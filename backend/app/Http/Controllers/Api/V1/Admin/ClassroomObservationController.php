<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Book;
use App\Models\ClassroomObservation;
use App\Models\ClassroomObservationRating;
use App\Models\ObservationForm;
use App\Models\Position;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\ProfessionalDevelopment\ProfessionalDevelopmentAccess;
use App\Support\Notifications\NotificationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * HRM > Professional Development > Classroom observation
 * (docs/hrm/professional-development.md). Managers schedule an observation
 * of a teacher (class, subject, form, observer, date); the observer — or a
 * manager — rates each criterion 1–5 and writes strengths, areas to improve
 * and next steps, then completes it; the teacher then reads it and
 * acknowledges it with a comment. Not counted in the Performance score.
 *
 * A teacher sees their own observations, but not the ratings and notes
 * until it is completed.
 */
final class ClassroomObservationController extends Controller
{
    private const LINK = '/admin/professional-development/observations';

    public function __construct(
        private readonly ProfessionalDevelopmentAccess $access,
        private readonly NotificationService $notifications,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->canUse($user), 403);
        $me = $this->access->staffId($user);

        $query = ClassroomObservation::query()->with(['teacher', 'observer', 'schoolClass:id,name', 'book:id,title']);
        $this->limitToOwn($query, $user, $me, $request->query('mine'));

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }
        if ($request->filled('staff_id')) {
            $query->where('staff_id', $request->integer('staff_id'));
        }
        if ($request->query('awaiting') === 'acknowledgement') {
            $query->where('status', 'completed')->whereNull('acknowledged_at');
        }
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(fn ($q) => $q
                ->whereHas('teacher', fn ($s) => $s->where('first_name', 'ilike', $like)->orWhere('last_name', 'ilike', $like)->orWhere('employee_code', 'ilike', $like))
                ->orWhereHas('schoolClass', fn ($c) => $c->where('name', 'ilike', $like))
                ->orWhereHas('book', fn ($b) => $b->where('title', 'ilike', $like))
                ->orWhere('focus', 'ilike', $like));
        }

        $rows = $query->orderByRaw("case status when 'scheduled' then 0 else 1 end")
            ->orderByRaw("case when status = 'scheduled' then observed_on end asc")
            ->orderByDesc('observed_on')->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 15), 1), 100));

        $rows->setCollection($rows->getCollection()->map(fn (ClassroomObservation $o) => $this->row($o, $user, $me, false)));

        return ApiResponse::success($rows);
    }

    public function summary(Request $request): JsonResponse
    {
        abort_unless($this->access->canViewAll($request->user()), 403);
        $year = now()->year;

        return ApiResponse::success([
            'scheduled' => ClassroomObservation::query()->where('status', 'scheduled')->count(),
            'completed_this_year' => ClassroomObservation::query()->where('status', 'completed')->whereYear('observed_on', $year)->count(),
            'awaiting_acknowledgement' => ClassroomObservation::query()->where('status', 'completed')->whereNull('acknowledged_at')->count(),
            'average_this_year' => ($avg = ClassroomObservation::query()->where('status', 'completed')->whereYear('observed_on', $year)->avg('average_rating')) !== null ? round((float) $avg, 2) : null,
        ]);
    }

    /** Teachers, observers, forms, classes (with their teachers) and subjects, for scheduling. */
    public function options(Request $request): JsonResponse
    {
        abort_unless($this->access->canManage($request->user()), 403);

        $teacherPositions = Position::teacherPositionIds();
        $staff = Staff::query()->active()->orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name', 'employee_code', 'position_id']);
        $person = fn (Staff $s) => ['id' => $s->id, 'name' => $s->fullName(), 'employee_code' => $s->employee_code];

        return ApiResponse::success([
            'teachers' => $staff->filter(fn (Staff $s) => in_array($s->position_id, $teacherPositions, true))->map($person)->values(),
            'observers' => $staff->map($person)->values(),
            'forms' => ObservationForm::query()->where('is_active', true)->withCount('criteria')->orderBy('name')->get()
                ->map(fn (ObservationForm $f) => ['id' => $f->id, 'name' => $f->name, 'criteria_count' => $f->criteria_count])->values(),
            'classes' => SchoolClass::query()->with('teachingStaff:id')
                ->whereIn('status', [SchoolClass::STATUS_UPCOMING, SchoolClass::STATUS_ACTIVE])->orderBy('name')->get(['id', 'name'])
                ->map(fn (SchoolClass $c) => ['id' => $c->id, 'name' => $c->name, 'teacher_ids' => $c->teachingStaff->pluck('id')->map(fn ($id) => (int) $id)->values()])->values(),
            'subjects' => Book::query()->active()->orderBy('title')->get(['id', 'title'])->map(fn (Book $b) => ['id' => $b->id, 'title' => $b->title])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->canManage($user), 403);
        $data = $this->validated($request, true);

        $observation = DB::connection('tenant')->transaction(function () use ($data, $user) {
            $form = ObservationForm::query()->with('criteria')->findOrFail($data['observation_form_id']);
            $observation = ClassroomObservation::query()->create([...$data, 'form_name' => $form->name, 'status' => 'scheduled', 'created_by' => $user->getKey()]);
            $this->copyCriteria($observation, $form);

            return $observation;
        });

        $this->notify(array_filter([$observation->staff_id, $observation->observer_staff_id]), NotificationType::OBSERVATION_SCHEDULED, $observation, $user);

        return ApiResponse::created($this->detail($observation, $user));
    }

    public function show(Request $request, ClassroomObservation $observation): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->canSee($observation, $user), 404);

        return ApiResponse::success($this->detail($observation, $user));
    }

    /** Reschedule, change who / what, or cancel. A new form is copied in while nothing is rated yet. */
    public function update(Request $request, ClassroomObservation $observation): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->canManage($user), 403);
        $data = $this->validated($request, false);

        DB::connection('tenant')->transaction(function () use ($observation, $data) {
            $formChanged = isset($data['observation_form_id']) && (int) $data['observation_form_id'] !== (int) $observation->observation_form_id;
            $observation->update($data);
            if ($formChanged && $observation->status === 'scheduled' && ! $observation->ratings()->whereNotNull('rating')->exists()) {
                $form = ObservationForm::query()->with('criteria')->findOrFail($data['observation_form_id']);
                $observation->ratings()->delete();
                $observation->update(['form_name' => $form->name]);
                $this->copyCriteria($observation, $form);
            }
        });

        return ApiResponse::success($this->detail($observation, $user));
    }

    /**
     * The observer (or a manager) records the ratings and notes; with
     * complete=true it is finished and the teacher is asked to read it.
     */
    public function record(Request $request, ClassroomObservation $observation): JsonResponse
    {
        $user = $request->user();
        $me = $this->access->staffId($user);
        $isObserver = $me !== null && $me === (int) $observation->observer_staff_id;
        abort_unless($this->access->canManage($user) || $isObserver, 403);
        abort_if($observation->status === 'cancelled', 422, __('This observation was cancelled.'));
        abort_if($observation->acknowledged_at !== null, 422, __('The teacher has already acknowledged this observation.'));

        $data = $request->validate([
            'ratings' => ['sometimes', 'array'],
            'ratings.*.id' => ['required', 'integer'],
            'ratings.*.rating' => ['nullable', 'integer', 'between:1,5'],
            'ratings.*.comment' => ['nullable', 'string', 'max:2000'],
            'strengths' => ['nullable', 'string', 'max:10000'],
            'improvements' => ['nullable', 'string', 'max:10000'],
            'next_steps' => ['nullable', 'string', 'max:10000'],
            'complete' => ['sometimes', 'boolean'],
        ]);

        $completing = ($data['complete'] ?? false) && $observation->status !== 'completed';

        DB::connection('tenant')->transaction(function () use ($observation, $data, $completing) {
            $ratings = $observation->ratings()->get()->keyBy('id');
            foreach ($data['ratings'] ?? [] as $row) {
                $ratings->get((int) $row['id'])?->update(['rating' => $row['rating'] ?? null, 'comment' => $row['comment'] ?? null]);
            }
            $average = $observation->ratings()->whereNotNull('rating')->avg('rating');
            abort_if($completing && $average === null, 422, __('Rate at least one criterion before completing.'));
            $observation->update([
                ...array_intersect_key($data, array_flip(['strengths', 'improvements', 'next_steps'])),
                'average_rating' => $average !== null ? round((float) $average, 2) : null,
                ...($completing ? ['status' => 'completed', 'completed_at' => now()] : []),
            ]);
        });

        if ($completing) {
            $this->notify([(int) $observation->staff_id], NotificationType::OBSERVATION_COMPLETED, $observation, $user);
        }

        return ApiResponse::success($this->detail($observation, $user));
    }

    /** The observed teacher confirms they have read it, with an optional comment. */
    public function acknowledge(Request $request, ClassroomObservation $observation): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->staffId($user) === (int) $observation->staff_id, 403);
        abort_unless($observation->status === 'completed', 422, __('Only a completed observation can be acknowledged.'));
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:5000']]);

        $observation->update(['acknowledged_at' => now(), 'teacher_comment' => $data['comment'] ?? null]);

        return ApiResponse::success($this->detail($observation, $user));
    }

    public function destroy(Request $request, ClassroomObservation $observation): JsonResponse
    {
        abort_unless($this->access->canManage($request->user()), 403);

        $observation->delete();

        return ApiResponse::noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $teacherPositions = Position::teacherPositionIds();

        return $request->validate([
            'staff_id' => [$required, 'integer', Rule::exists('tenant.staff', 'id')->whereIn('position_id', $teacherPositions)->whereNull('deleted_at')],
            'observer_staff_id' => ['nullable', 'integer', 'different:staff_id', Rule::exists('tenant.staff', 'id')->whereNull('deleted_at')],
            'class_id' => ['nullable', 'integer', Rule::exists('tenant.classes', 'id')],
            'book_id' => ['nullable', 'integer', Rule::exists('tenant.books', 'id')],
            'observation_form_id' => [$required, 'integer', Rule::exists('tenant.observation_forms', 'id')],
            'observed_on' => [$required, 'date_format:Y-m-d'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
            'focus' => ['nullable', 'string', 'max:500'],
            'status' => ['sometimes', Rule::in(['scheduled', 'cancelled'])],
        ]);
    }

    private function copyCriteria(ClassroomObservation $observation, ObservationForm $form): void
    {
        foreach ($form->criteria as $i => $c) {
            ClassroomObservationRating::query()->create([
                'classroom_observation_id' => $observation->id,
                'section' => $c->section,
                'criterion' => $c->criterion,
                'sort_order' => $i,
            ]);
        }
    }

    /**
     * Without view-all: only observations of me or by me. ?mine=observed
     * (of me) or ?mine=observer (by me) narrows it for anyone.
     *
     * @param  Builder<ClassroomObservation>  $query
     */
    private function limitToOwn(Builder $query, User $user, ?int $me, mixed $mine): void
    {
        if ($mine === 'observed') {
            $query->where('staff_id', $me ?? 0);
        } elseif ($mine === 'observer') {
            $query->where('observer_staff_id', $me ?? 0);
        } elseif (! $this->access->canViewAll($user)) {
            $query->where(fn ($q) => $q->where('staff_id', $me ?? 0)->orWhere('observer_staff_id', $me ?? 0));
        }
    }

    private function canSee(ClassroomObservation $o, User $user): bool
    {
        if ($this->access->canViewAll($user)) {
            return true;
        }
        $me = $this->access->staffId($user);

        return $this->access->canUse($user) && $me !== null && ($me === (int) $o->staff_id || $me === (int) $o->observer_staff_id);
    }

    /** @return array<string, mixed> */
    private function detail(ClassroomObservation $o, User $user): array
    {
        $o->load(['teacher', 'observer', 'schoolClass:id,name', 'book:id,title', 'ratings']);

        return $this->row($o, $user, $this->access->staffId($user), true);
    }

    /** @return array<string, mixed> */
    private function row(ClassroomObservation $o, User $user, ?int $me, bool $withRatings): array
    {
        $isTeacher = $me !== null && $me === (int) $o->staff_id;
        $isObserver = $me !== null && $me === (int) $o->observer_staff_id;
        $canManage = $this->access->canManage($user);
        // The observed teacher sees the outcome only once it is completed.
        $showOutcome = $o->status === 'completed' || $canManage || $isObserver || $this->access->canViewAll($user);
        $person = fn (?Staff $s) => $s ? ['id' => $s->id, 'name' => $s->fullName(), 'employee_code' => $s->employee_code] : null;

        return [
            'id' => $o->id,
            'staff_id' => $o->staff_id,
            'teacher' => $person($o->teacher),
            'observer_staff_id' => $o->observer_staff_id,
            'observer' => $person($o->observer),
            'class_id' => $o->class_id,
            'class_name' => $o->schoolClass?->name,
            'book_id' => $o->book_id,
            'subject' => $o->book?->title,
            'observation_form_id' => $o->observation_form_id,
            'form_name' => $o->form_name,
            'observed_on' => $o->observed_on?->toDateString(),
            'start_time' => $o->start_time ? substr((string) $o->start_time, 0, 5) : null,
            'end_time' => $o->end_time ? substr((string) $o->end_time, 0, 5) : null,
            'focus' => $o->focus,
            'status' => $o->status,
            'average_rating' => $showOutcome && $o->average_rating !== null ? (float) $o->average_rating : null,
            'completed_at' => $o->completed_at?->toIso8601String(),
            'acknowledged_at' => $o->acknowledged_at?->toIso8601String(),
            'teacher_comment' => $o->teacher_comment,
            'strengths' => $showOutcome ? $o->strengths : null,
            'improvements' => $showOutcome ? $o->improvements : null,
            'next_steps' => $showOutcome ? $o->next_steps : null,
            'ratings' => $withRatings && $showOutcome ? $o->ratings->map(fn (ClassroomObservationRating $r) => [
                'id' => $r->id, 'section' => $r->section, 'criterion' => $r->criterion, 'rating' => $r->rating, 'comment' => $r->comment,
            ])->values() : null,
            'can' => [
                'record' => ($canManage || $isObserver) && $o->status !== 'cancelled' && $o->acknowledged_at === null,
                'manage' => $canManage,
                'acknowledge' => $isTeacher && $o->status === 'completed' && $o->acknowledged_at === null,
            ],
        ];
    }

    /** @param  list<int>  $staffIds */
    private function notify(array $staffIds, string $type, ClassroomObservation $o, User $actor): void
    {
        $users = Staff::query()->whereIn('id', $staffIds)->with('user')->get()->pluck('user')->filter()
            ->reject(fn (User $u) => $u->getKey() === $actor->getKey());
        if ($users->isEmpty()) {
            return;
        }
        $o->loadMissing(['teacher', 'schoolClass:id,name']);

        $this->notifications->notifyMany($users, $type, [
            'observation_id' => $o->id,
            'teacher_name' => $o->teacher?->fullName(),
            'class_name' => $o->schoolClass?->name ?? '—',
            'date' => $o->observed_on->toDateString(),
        ], link: self::LINK);
    }
}
