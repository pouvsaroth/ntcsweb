<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Book;
use App\Models\CurriculumLesson;
use App\Models\LessonPlan;
use App\Models\LessonPlanFile;
use App\Models\Position;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\TeachingLog;
use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Academic > Lesson plans (docs/academics/lesson-plans.md): lesson plans
 * (objectives, materials, activities, homework, files) with their review,
 * the daily teaching log, curriculum progress and the delay report.
 *
 * Who: `lesson-plans.write` writes one's own (a teacher = their Staff
 * record); with `lesson-plans.view` one sees — and may write for — every
 * teacher; `lesson-plans.review` approves or returns submitted plans.
 */
final class LessonPlanController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    // --- Pickers --------------------------------------------------------------------

    public function options(Request $request): JsonResponse
    {
        $this->canAny($request, Permissions::LESSON_PLANS_VIEW, Permissions::LESSON_PLANS_WRITE, Permissions::LESSON_PLANS_REVIEW);
        $me = $this->me($request);
        $all = $this->seesAll($request);

        $classes = SchoolClass::query()->with(['academicYear:id,name', 'gradeLevel:id,name'])
            ->whereIn('status', [SchoolClass::STATUS_UPCOMING, SchoolClass::STATUS_ACTIVE])
            ->when(! $all, fn ($q) => $q->whereIn('id', $this->myClassIds($me)))
            ->orderBy('name')->get();

        return ApiResponse::success([
            'me' => $me,
            'sees_all' => $all,
            'classes' => $classes->map(fn (SchoolClass $c) => [
                'id' => $c->id, 'name' => $c->name, 'grade_level_id' => $c->grade_level_id,
                'grade_level' => $c->gradeLevel?->name, 'academic_year' => $c->academicYear?->name,
            ])->values(),
            'subjects' => Book::query()->active()->orderBy('title')->get(['id', 'title'])->map(fn ($b) => ['id' => $b->id, 'title' => $b->title])->values(),
            'teachers' => $all
                ? Staff::query()->whereIn('position_id', Position::teacherPositionIds())->where('status', Staff::STATUS_ACTIVE)->orderBy('first_name')->get()
                    ->map(fn (Staff $s) => ['id' => $s->id, 'name' => $s->fullName()])->values()
                : collect($me ? [['id' => $me, 'name' => Staff::query()->find($me)?->fullName()]] : []),
        ]);
    }

    /** The curriculum lessons of a class's grade for one subject (School setup > Curriculum). */
    public function curriculumLessons(Request $request): JsonResponse
    {
        $this->canAny($request, Permissions::LESSON_PLANS_VIEW, Permissions::LESSON_PLANS_WRITE, Permissions::LESSON_PLANS_REVIEW);
        $request->validate(['class_id' => ['required', 'integer'], 'book_id' => ['required', 'integer']]);
        $grade = SchoolClass::query()->whereKey($request->integer('class_id'))->value('grade_level_id');

        $lessons = $grade === null ? collect() : CurriculumLesson::query()->where('grade_level_id', $grade)
            ->where('book_id', $request->integer('book_id'))->orderBy('sort_order')->orderBy('id')->get(['id', 'title', 'hours', 'sort_order']);

        return ApiResponse::success($lessons->map(fn ($l) => ['id' => $l->id, 'title' => $l->title, 'hours' => $l->hours !== null ? (float) $l->hours : null])->values());
    }

    // --- Lesson plans ---------------------------------------------------------------

    public function plans(Request $request): JsonResponse
    {
        $this->canAny($request, Permissions::LESSON_PLANS_VIEW, Permissions::LESSON_PLANS_WRITE, Permissions::LESSON_PLANS_REVIEW);
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        $plans = $this->visible($request, LessonPlan::query())
            ->with(['staff', 'schoolClass:id,name', 'book:id,title', 'curriculumLesson:id,title'])
            ->withCount(['files', 'logs as taught_count' => fn ($q) => $q->whereIn('status', ['done', 'partial'])])
            ->when($request->filled('from'), fn ($q) => $q->whereDate('planned_date', '>=', (string) $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('planned_date', '<=', (string) $request->input('to')))
            ->when($request->filled('staff_id'), fn ($q) => $q->where('staff_id', $request->integer('staff_id')))
            ->when($request->filled('class_id'), fn ($q) => $q->where('class_id', $request->integer('class_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->input('status')))
            ->orderBy('planned_date')->orderBy('id')->limit(500)->get();

        return ApiResponse::success($plans->map(fn (LessonPlan $p) => $this->planRow($p))->values());
    }

    public function show(Request $request, LessonPlan $plan): JsonResponse
    {
        $this->canSee($request, $plan);
        $plan->load(['staff', 'schoolClass:id,name', 'book:id,title', 'curriculumLesson:id,title', 'files', 'logs']);
        $reviewer = $plan->reviewed_by ? User::query()->whereKey($plan->reviewed_by)->value('name') : null;

        return ApiResponse::success($this->planRow($plan) + [
            'objectives' => $plan->objectives,
            'materials' => $plan->materials,
            'activities' => $plan->activities,
            'homework' => $plan->homework,
            'homework_due' => $plan->homework_due?->toDateString(),
            'review_note' => $plan->review_note,
            'reviewed_by' => $reviewer,
            'reviewed_at' => $plan->reviewed_at?->toDateString(),
            'files' => $plan->files->map(fn (LessonPlanFile $f) => ['id' => $f->id, 'name' => $f->file_name, 'size' => $f->size, 'mime' => $f->mime])->values(),
            'logs' => $plan->logs->map(fn (TeachingLog $l) => ['id' => $l->id, 'taught_on' => $l->taught_on->toDateString(), 'status' => $l->status, 'note' => $l->note])->values(),
            'can_edit' => $this->canEdit($request, $plan),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->can($request, Permissions::LESSON_PLANS_WRITE);
        $data = $this->validatedPlan($request);
        $plan = LessonPlan::query()->create($data + ['created_by' => $request->user()->getKey()]);

        return ApiResponse::created(['id' => $plan->id]);
    }

    public function update(Request $request, LessonPlan $plan): JsonResponse
    {
        $this->can($request, Permissions::LESSON_PLANS_WRITE);
        abort_unless($this->canEdit($request, $plan), 403);
        $plan->update($this->validatedPlan($request, $plan));
        // A returned plan that is changed goes back to draft until it is sent again.
        if ($plan->status === LessonPlan::RETURNED) {
            $plan->forceFill(['status' => LessonPlan::DRAFT])->save();
        }

        return ApiResponse::success(['id' => $plan->id, 'status' => $plan->status]);
    }

    public function destroy(Request $request, LessonPlan $plan): JsonResponse
    {
        $this->can($request, Permissions::LESSON_PLANS_WRITE);
        abort_unless($this->canEdit($request, $plan), 403);
        foreach ($plan->files as $file) {
            Storage::disk('local')->delete($file->file_path);
        }
        $plan->delete();

        return ApiResponse::noContent();
    }

    public function submit(Request $request, LessonPlan $plan): JsonResponse
    {
        $this->can($request, Permissions::LESSON_PLANS_WRITE);
        abort_unless($this->canEdit($request, $plan), 403);
        $plan->forceFill(['status' => LessonPlan::SUBMITTED, 'submitted_at' => now()])->save();

        return ApiResponse::success(['status' => $plan->status]);
    }

    /** Lesson review: approve, or return with what to change. */
    public function review(Request $request, LessonPlan $plan): JsonResponse
    {
        $this->can($request, Permissions::LESSON_PLANS_REVIEW);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'return'])],
            'note' => ['nullable', 'string', 'max:2000', 'required_if:decision,return'],
        ]);
        if ($plan->status !== LessonPlan::SUBMITTED) {
            throw ValidationException::withMessages(['decision' => __('Only a submitted plan can be reviewed.')]);
        }
        $plan->forceFill([
            'status' => $data['decision'] === 'approve' ? LessonPlan::APPROVED : LessonPlan::RETURNED,
            'review_note' => $data['note'] ?? null,
            'reviewed_by' => $request->user()->getKey(),
            'reviewed_at' => now(),
        ])->save();

        return ApiResponse::success(['status' => $plan->status]);
    }

    // --- Attachments -----------------------------------------------------------------

    public function upload(Request $request, LessonPlan $plan): JsonResponse
    {
        $this->can($request, Permissions::LESSON_PLANS_WRITE);
        abort_unless($this->canEdit($request, $plan), 403);
        $request->validate(['file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,ppt,pptx,xls,xlsx,txt']]);
        $file = $request->file('file');
        $path = $file->storeAs($this->context->getOrFail()->storagePath('lesson-plans', (string) $plan->id), Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: 'bin'), 'local');
        $row = $plan->files()->create([
            'file_path' => $path, 'file_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime' => $file->getMimeType(), 'size' => (int) $file->getSize(), 'uploaded_by' => $request->user()->getKey(),
        ]);

        return ApiResponse::created(['id' => $row->id, 'name' => $row->file_name, 'size' => $row->size, 'mime' => $row->mime]);
    }

    public function download(Request $request, LessonPlanFile $file): StreamedResponse
    {
        $this->canSee($request, $file->plan);
        abort_unless(Storage::disk('local')->exists($file->file_path), 404);
        $inline = str_starts_with((string) $file->mime, 'image/') || $file->mime === 'application/pdf';

        return Storage::disk('local')->response($file->file_path, $file->file_name, ['Content-Type' => $file->mime ?? 'application/octet-stream'], $inline ? 'inline' : 'attachment');
    }

    public function removeFile(Request $request, LessonPlanFile $file): JsonResponse
    {
        $this->can($request, Permissions::LESSON_PLANS_WRITE);
        abort_unless($this->canEdit($request, $file->plan), 403);
        Storage::disk('local')->delete($file->file_path);
        $file->delete();

        return ApiResponse::noContent();
    }

    // --- Daily teaching log ----------------------------------------------------------

    public function logs(Request $request): JsonResponse
    {
        $this->canAny($request, Permissions::LESSON_PLANS_VIEW, Permissions::LESSON_PLANS_WRITE);
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        $logs = $this->visible($request, TeachingLog::query())
            ->with(['staff', 'schoolClass:id,name', 'book:id,title', 'curriculumLesson:id,title'])
            ->when($request->filled('from'), fn ($q) => $q->whereDate('taught_on', '>=', (string) $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('taught_on', '<=', (string) $request->input('to')))
            ->when($request->filled('staff_id'), fn ($q) => $q->where('staff_id', $request->integer('staff_id')))
            ->when($request->filled('class_id'), fn ($q) => $q->where('class_id', $request->integer('class_id')))
            ->orderByDesc('taught_on')->orderByDesc('id')->limit(500)->get();

        return ApiResponse::success($logs->map(fn (TeachingLog $l) => $this->logRow($l))->values());
    }

    public function storeLog(Request $request): JsonResponse
    {
        $this->can($request, Permissions::LESSON_PLANS_WRITE);
        $log = TeachingLog::query()->create($this->validatedLog($request) + ['created_by' => $request->user()->getKey()]);

        return ApiResponse::created($this->logRow($log->load(['staff', 'schoolClass:id,name', 'book:id,title', 'curriculumLesson:id,title'])));
    }

    public function updateLog(Request $request, TeachingLog $log): JsonResponse
    {
        $this->can($request, Permissions::LESSON_PLANS_WRITE);
        abort_unless($this->seesAll($request) || $log->staff_id === $this->me($request), 403);
        $log->update($this->validatedLog($request));

        return ApiResponse::success($this->logRow($log->load(['staff', 'schoolClass:id,name', 'book:id,title', 'curriculumLesson:id,title'])));
    }

    public function destroyLog(Request $request, TeachingLog $log): JsonResponse
    {
        $this->can($request, Permissions::LESSON_PLANS_WRITE);
        abort_unless($this->seesAll($request) || $log->staff_id === $this->me($request), 403);
        $log->delete();

        return ApiResponse::noContent();
    }

    // --- Curriculum progress and delays ------------------------------------------------

    /**
     * Per subject of a class: its curriculum lessons, how many are taught
     * (a teaching log marked done), partly taught, and what is next.
     */
    public function progress(Request $request): JsonResponse
    {
        $this->canAny($request, Permissions::LESSON_PLANS_VIEW, Permissions::LESSON_PLANS_WRITE);
        $request->validate(['class_id' => ['required', 'integer', Rule::exists('tenant.classes', 'id')]]);
        $class = SchoolClass::query()->findOrFail($request->integer('class_id'));
        if (! $this->seesAll($request)) {
            abort_unless(in_array($class->id, $this->myClassIds($this->me($request)), true), 403);
        }
        if ($class->grade_level_id === null) {
            return ApiResponse::success(['class' => $class->name, 'has_grade' => false, 'subjects' => []]);
        }

        $lessons = CurriculumLesson::query()->where('grade_level_id', $class->grade_level_id)->orderBy('sort_order')->orderBy('id')->get()->groupBy('book_id');
        $statuses = TeachingLog::query()->where('class_id', $class->id)->whereNotNull('curriculum_lesson_id')
            ->get(['curriculum_lesson_id', 'status'])->groupBy('curriculum_lesson_id')
            ->map(fn ($rows) => $rows->contains('status', 'done') ? 'done' : ($rows->contains('status', 'partial') ? 'partial' : null));
        $titles = Book::query()->whereIn('id', $lessons->keys())->pluck('title', 'id');

        $subjects = $lessons->map(function ($rows, $bookId) use ($statuses, $titles) {
            $done = $rows->filter(fn ($l) => ($statuses[$l->id] ?? null) === 'done');
            $partial = $rows->filter(fn ($l) => ($statuses[$l->id] ?? null) === 'partial');
            $next = $rows->first(fn ($l) => ($statuses[$l->id] ?? null) !== 'done');

            return [
                'book_id' => (int) $bookId,
                'subject' => $titles[$bookId] ?? null,
                'total' => $rows->count(),
                'done' => $done->count(),
                'partial' => $partial->count(),
                'remaining' => $rows->count() - $done->count(),
                'percent' => $rows->count() ? (int) round($done->count() / $rows->count() * 100) : 0,
                'next' => $next?->title,
                'lessons' => $rows->map(fn ($l) => ['id' => $l->id, 'title' => $l->title, 'status' => $statuses[$l->id] ?? null])->values(),
            ];
        })->sortBy('subject')->values();

        return ApiResponse::success(['class' => $class->name, 'has_grade' => true, 'subjects' => $subjects]);
    }

    /** Teaching delay report: planned lessons whose day has passed with nothing taught yet. */
    public function delays(Request $request): JsonResponse
    {
        $this->canAny($request, Permissions::LESSON_PLANS_VIEW, Permissions::LESSON_PLANS_WRITE);
        $today = Carbon::today();

        $plans = $this->visible($request, LessonPlan::query())
            ->with(['staff', 'schoolClass:id,name', 'book:id,title'])
            ->whereDate('planned_date', '<', $today->toDateString())
            ->whereDoesntHave('logs', fn ($q) => $q->whereIn('status', ['done', 'partial']))
            // Not yet taught as a curriculum lesson either (logged without the plan).
            ->where(fn ($q) => $q->whereNull('curriculum_lesson_id')->orWhereNotExists(fn ($e) => $e->select(DB::raw(1))->from('teaching_logs as t')
                ->whereColumn('t.curriculum_lesson_id', 'lesson_plans.curriculum_lesson_id')->whereColumn('t.class_id', 'lesson_plans.class_id')
                ->whereIn('t.status', ['done', 'partial'])))
            ->when($request->filled('staff_id'), fn ($q) => $q->where('staff_id', $request->integer('staff_id')))
            ->when($request->filled('class_id'), fn ($q) => $q->where('class_id', $request->integer('class_id')))
            ->orderBy('planned_date')->limit(500)->get();

        return ApiResponse::success($plans->map(fn (LessonPlan $p) => $this->planRow($p) + [
            'days_late' => (int) $p->planned_date->diffInDays($today),
        ])->values());
    }

    // --- Helpers -------------------------------------------------------------------------

    private function me(Request $request): ?int
    {
        $id = Staff::query()->where('user_id', $request->user()->getKey())->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function seesAll(Request $request): bool
    {
        return $request->user()->hasAnyPermission(Permissions::LESSON_PLANS_VIEW, Permissions::LESSON_PLANS_REVIEW);
    }

    /** @return list<int> */
    private function myClassIds(?int $staffId): array
    {
        if ($staffId === null) {
            return [];
        }
        $db = DB::connection('tenant');

        return $db->table('class_teachers')->where('staff_id', $staffId)->pluck('class_id')
            ->merge($db->table('teacher_assignments')->where('staff_id', $staffId)->pluck('class_id'))
            ->merge($db->table('class_schedules')->where('staff_id', $staffId)->pluck('class_id'))
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** Everyone's for those who see all; otherwise one's own. */
    private function visible(Request $request, Builder $query): Builder
    {
        return $this->seesAll($request) ? $query : $query->where('staff_id', $this->me($request) ?? 0);
    }

    private function canSee(Request $request, LessonPlan $plan): void
    {
        abort_unless($this->seesAll($request) || ($request->user()->hasPermission(Permissions::LESSON_PLANS_WRITE) && $plan->staff_id === $this->me($request)), 403);
    }

    /** Its teacher while draft/returned; someone who sees all at any time before approval. */
    private function canEdit(Request $request, LessonPlan $plan): bool
    {
        if (! $request->user()->hasPermission(Permissions::LESSON_PLANS_WRITE)) {
            return false;
        }
        if ($this->seesAll($request)) {
            return $plan->status !== LessonPlan::APPROVED || $request->user()->hasPermission(Permissions::LESSON_PLANS_REVIEW);
        }

        return $plan->staff_id === $this->me($request) && $plan->isEditable();
    }

    /** @return array<string, mixed> */
    private function validatedPlan(Request $request, ?LessonPlan $plan = null): array
    {
        $data = $request->validate([
            'staff_id' => ['nullable', 'integer', Rule::exists('tenant.staff', 'id')->whereIn('position_id', Position::teacherPositionIds())->whereNull('deleted_at')],
            'class_id' => ['required', 'integer', Rule::exists('tenant.classes', 'id')->whereNull('deleted_at')],
            'book_id' => ['nullable', 'integer', Rule::exists('tenant.books', 'id')],
            'curriculum_lesson_id' => ['nullable', 'integer', Rule::exists('tenant.curriculum_lessons', 'id')],
            'planned_date' => ['required', 'date'],
            'title' => ['required', 'string', 'max:255'],
            'objectives' => ['nullable', 'string', 'max:5000'],
            'materials' => ['nullable', 'string', 'max:5000'],
            'activities' => ['nullable', 'string', 'max:10000'],
            'homework' => ['nullable', 'string', 'max:5000'],
            'homework_due' => ['nullable', 'date', 'after_or_equal:planned_date'],
        ]);

        return $this->ownedBy($request, $data, $plan?->staff_id);
    }

    /** @return array<string, mixed> */
    private function validatedLog(Request $request): array
    {
        $data = $request->validate([
            'staff_id' => ['nullable', 'integer', Rule::exists('tenant.staff', 'id')->whereIn('position_id', Position::teacherPositionIds())->whereNull('deleted_at')],
            'class_id' => ['required', 'integer', Rule::exists('tenant.classes', 'id')->whereNull('deleted_at')],
            'book_id' => ['nullable', 'integer', Rule::exists('tenant.books', 'id')],
            'curriculum_lesson_id' => ['nullable', 'integer', Rule::exists('tenant.curriculum_lessons', 'id')],
            'lesson_plan_id' => ['nullable', 'integer', Rule::exists('tenant.lesson_plans', 'id')],
            'taught_on' => ['required', 'date', 'before_or_equal:today'],
            'title' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(TeachingLog::STATUSES)],
            'homework' => ['nullable', 'string', 'max:5000'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        return $this->ownedBy($request, $data, null);
    }

    /**
     * Whose it is: the teacher themself; someone who sees all may name any
     * teacher. A teacher writes only for their own classes.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function ownedBy(Request $request, array $data, ?int $current): array
    {
        if ($this->seesAll($request)) {
            $data['staff_id'] = $data['staff_id'] ?? $current ?? $this->me($request);
            if ($data['staff_id'] === null) {
                throw ValidationException::withMessages(['staff_id' => __('Choose the teacher.')]);
            }

            return $data;
        }

        $me = $this->me($request);
        if ($me === null) {
            throw ValidationException::withMessages(['staff_id' => __('Your account is not linked to a staff record.')]);
        }
        if (! in_array((int) $data['class_id'], $this->myClassIds($me), true)) {
            throw ValidationException::withMessages(['class_id' => __('You can write only for the classes you teach.')]);
        }
        $data['staff_id'] = $me;

        return $data;
    }

    /** @return array<string, mixed> */
    private function planRow(LessonPlan $p): array
    {
        return [
            'id' => $p->id,
            'staff_id' => $p->staff_id,
            'teacher' => $p->staff?->fullName(),
            'class_id' => $p->class_id,
            'class' => $p->schoolClass?->name,
            'book_id' => $p->book_id,
            'subject' => $p->book?->title,
            'curriculum_lesson_id' => $p->curriculum_lesson_id,
            'curriculum_lesson' => $p->curriculumLesson?->title,
            'planned_date' => $p->planned_date->toDateString(),
            'title' => $p->title,
            'status' => $p->status,
            'files_count' => (int) ($p->files_count ?? 0),
            'taught' => (int) ($p->taught_count ?? 0) > 0,
        ];
    }

    /** @return array<string, mixed> */
    private function logRow(TeachingLog $l): array
    {
        return [
            'id' => $l->id,
            'staff_id' => $l->staff_id,
            'teacher' => $l->staff?->fullName(),
            'class_id' => $l->class_id,
            'class' => $l->schoolClass?->name,
            'book_id' => $l->book_id,
            'subject' => $l->book?->title,
            'curriculum_lesson_id' => $l->curriculum_lesson_id,
            'curriculum_lesson' => $l->curriculumLesson?->title,
            'lesson_plan_id' => $l->lesson_plan_id,
            'taught_on' => $l->taught_on->toDateString(),
            'title' => $l->title,
            'status' => $l->status,
            'homework' => $l->homework,
            'note' => $l->note,
        ];
    }

    private function can(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission), 403);
    }

    private function canAny(Request $request, string ...$permissions): void
    {
        abort_unless($request->user()->hasAnyPermission(...$permissions), 403);
    }
}
