<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AssessmentComponent;
use App\Models\CompetencyCriterion;
use App\Models\CompetencyLevel;
use App\Models\GradeCorrection;
use App\Models\GradeEntryHistory;
use App\Models\ResultSheet;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\Academic\Gradebook\GradebookAccess;
use App\Services\Academic\Gradebook\ResultApprovals;
use App\Services\Approvals\ApprovalFlow;
use App\Support\Approvals\DocumentType;
use App\Support\Authorization\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Academic > Examination: Result Approval and Grade Correction
 * (docs/academics/gradebook.md, 7c). Both are eApprovals items — listed in
 * the Approvals queue too (`approval_queue=1`).
 */
final class ResultApprovalController extends Controller
{
    public function __construct(
        private readonly GradebookAccess $access,
        private readonly ResultApprovals $approvals,
        private readonly ApprovalFlow $flow,
    ) {}

    // --- Result sheets ------------------------------------------------------------

    public function sheets(Request $request): JsonResponse
    {
        $user = $request->user();
        $filters = $request->validate([
            'academic_term_id' => ['nullable', 'integer'],
            'class_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:60'],
            'approval_queue' => ['nullable', 'boolean'],
        ]);
        $canViewAll = $this->canViewAll($user);
        $queue = (bool) ($filters['approval_queue'] ?? false);
        abort_unless($canViewAll || $this->flow->isApproverFor($user, [DocumentType::RESULT_SHEET])
            || $user->hasAnyPermission(Permissions::GRADEBOOK_VIEW, Permissions::GRADEBOOK_ENTER), 403);

        $query = ResultSheet::query()->with(['schoolClass:id,name', 'book:id,title', 'term:id,name'])
            ->when($filters['academic_term_id'] ?? null, fn ($q, $v) => $q->where('academic_term_id', $v))
            ->when($filters['class_id'] ?? null, fn ($q, $v) => $q->where('class_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->whereIn('status', explode(',', $v)));

        if ($queue) {
            // The Approvals queue: submitted and decided sheets only.
            $query->where('status', '!=', ResultSheet::STATUS_DRAFT);
            $this->flow->scopeQueue($query, ResultSheet::class, $user, $canViewAll);
        } elseif (! $canViewAll) {
            $this->scopeToOwnClasses($query, $user);
        }

        $sheets = $query->orderByDesc('updated_at')->limit(300)->get();
        $this->flow->attachProgress($sheets, $user);
        $names = User::query()->whereIn('id', $sheets->pluck('requested_by')->merge($sheets->pluck('decided_by'))->filter()->unique()->all())->pluck('name', 'id');

        return ApiResponse::success($sheets->map(fn (ResultSheet $s) => [
            'id' => $s->id,
            'class' => $s->schoolClass?->only(['id', 'name']),
            'subject' => $s->book?->only(['id', 'title']),
            'term' => $s->term?->only(['id', 'name']),
            'status' => $s->status,
            'requested_by' => $names[$s->requested_by] ?? null,
            'submitted_at' => $s->submitted_at?->toIso8601String(),
            'decided_by' => $names[$s->decided_by] ?? null,
            'decided_at' => $s->decided_at?->toIso8601String(),
            'decision_note' => $s->decision_note,
            'approval_flow' => $s->getRelation('approvalFlow'),
            'can_submit' => $s->isEditable() && $s->schoolClass && $this->access->canEnter($user, $s->schoolClass, $s->book_id),
            'can_decide' => $s->status === ResultSheet::STATUS_PENDING && $this->flow->mayDecide($s, $user, 'approve'),
            'created_at' => $s->created_at?->toIso8601String(),
        ])->values());
    }

    public function submit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'class_id' => ['required', 'integer', Rule::exists('tenant.classes', 'id')->whereNull('deleted_at')],
            'book_id' => ['required', 'integer'],
            'academic_term_id' => ['required', 'integer'],
        ]);
        $class = SchoolClass::query()->findOrFail($data['class_id']);
        abort_unless($this->access->canEnter($request->user(), $class, (int) $data['book_id']), 403);
        $sheet = ResultSheet::query()->where('class_id', $class->id)->where('book_id', $data['book_id'])->where('academic_term_id', $data['academic_term_id'])->first();
        abort_if($sheet === null, 422, __('Nothing entered yet.'));

        $sheet = $this->approvals->submit($sheet, $request->user());

        return ApiResponse::success(['id' => $sheet->id, 'status' => $sheet->status]);
    }

    public function approve(Request $request, ResultSheet $resultSheet): JsonResponse
    {
        $sheet = $this->approvals->approveSheet($resultSheet, $request->user());

        return ApiResponse::success(['id' => $sheet->id, 'status' => $sheet->status]);
    }

    public function return(Request $request, ResultSheet $resultSheet): JsonResponse
    {
        abort_unless($this->flow->mayDecide($resultSheet, $request->user(), 'reject'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $sheet = $this->approvals->returnSheet($resultSheet, $data['reason'], $request->user());

        return ApiResponse::success(['id' => $sheet->id, 'status' => $sheet->status]);
    }

    // --- Grade corrections ----------------------------------------------------------

    public function corrections(Request $request): JsonResponse
    {
        $user = $request->user();
        $filters = $request->validate([
            'academic_term_id' => ['nullable', 'integer'],
            'class_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:60'],
            'approval_queue' => ['nullable', 'boolean'],
        ]);
        $canViewAll = $this->canViewAll($user);
        abort_unless($canViewAll || $this->flow->isApproverFor($user, [DocumentType::GRADE_CORRECTION])
            || $user->hasAnyPermission(Permissions::GRADEBOOK_VIEW, Permissions::GRADEBOOK_ENTER), 403);

        $query = GradeCorrection::query()->with([
            'sheet.schoolClass:id,name', 'sheet.book:id,title', 'sheet.term:id,name', 'student:id,student_code,first_name,last_name', 'component', 'criterion',
        ])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->whereIn('status', explode(',', $v)))
            ->when($filters['academic_term_id'] ?? null, fn ($q, $v) => $q->whereHas('sheet', fn ($s) => $s->where('academic_term_id', $v)))
            ->when($filters['class_id'] ?? null, fn ($q, $v) => $q->whereHas('sheet', fn ($s) => $s->where('class_id', $v)));

        if ($filters['approval_queue'] ?? false) {
            $this->flow->scopeQueue($query, GradeCorrection::class, $user, $canViewAll);
        } elseif (! $canViewAll) {
            $query->where(fn ($q) => $q->where('requested_by', $user->getKey())
                ->orWhereHas('sheet', fn ($s) => $this->scopeToOwnClasses($s, $user)));
        }

        $rows = $query->orderByDesc('created_at')->limit(300)->get();
        $this->flow->attachProgress($rows, $user);
        $names = User::query()->whereIn('id', $rows->pluck('requested_by')->merge($rows->pluck('decided_by'))->filter()->unique()->all())->pluck('name', 'id');
        $levels = CompetencyLevel::query()->whereIn('id', $rows->pluck('old_level_id')->merge($rows->pluck('new_level_id'))->filter()->unique()->all())->get()->keyBy('id');

        return ApiResponse::success($rows->map(fn (GradeCorrection $c) => [
            'id' => $c->id,
            'reference' => 'GC-'.str_pad((string) $c->id, 6, '0', STR_PAD_LEFT),
            'class' => $c->sheet?->schoolClass?->only(['id', 'name']),
            'subject' => $c->sheet?->book?->only(['id', 'title']),
            'term' => $c->sheet?->term?->only(['id', 'name']),
            'student' => $c->student ? ['id' => $c->student->id, 'code' => $c->student->student_code, 'name' => $c->student->fullName()] : null,
            'what' => $c->component
                ? ['name' => $c->component->name, 'name_km' => $c->component->name_km, 'occurrence' => $c->occurrence, 'occurrences' => $c->component->occurrences, 'max_score' => (float) $c->component->max_score]
                : ['name' => $c->criterion?->name, 'name_km' => $c->criterion?->name_km, 'occurrence' => null, 'occurrences' => null, 'max_score' => null],
            'old' => $this->value($c->old_score, $c->old_absent, $levels->get($c->old_level_id)),
            'new' => $this->value($c->new_score, $c->new_absent, $levels->get($c->new_level_id)),
            'reason' => $c->reason,
            'status' => $c->status,
            'requested_by' => $names[$c->requested_by] ?? null,
            'decided_by' => $names[$c->decided_by] ?? null,
            'decided_at' => $c->decided_at?->toIso8601String(),
            'decision_reason' => $c->decision_reason,
            'approval_flow' => $c->getRelation('approvalFlow'),
            'can_decide' => $c->status === GradeCorrection::STATUS_PENDING && $this->flow->mayDecide($c, $user, 'approve'),
            'can_withdraw' => $c->status === GradeCorrection::STATUS_PENDING && (int) $c->requested_by === (int) $user->getKey(),
            'created_at' => $c->created_at?->toIso8601String(),
        ])->values());
    }

    public function storeCorrection(Request $request): JsonResponse
    {
        $data = $request->validate([
            'class_id' => ['required', 'integer', Rule::exists('tenant.classes', 'id')->whereNull('deleted_at')],
            'book_id' => ['required', 'integer', Rule::exists('tenant.books', 'id')],
            'academic_term_id' => ['required', 'integer', Rule::exists('tenant.academic_terms', 'id')],
            'student_id' => ['required', 'integer'],
            'component_id' => ['nullable', 'integer'],
            'occurrence' => ['nullable', 'integer', 'min:1', 'max:12'],
            'criterion_id' => ['nullable', 'integer'],
            'score' => ['nullable'],
            'absent' => ['nullable', 'boolean'],
            'level_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        $class = SchoolClass::query()->findOrFail($data['class_id']);
        abort_unless($this->access->canEnter($request->user(), $class, (int) $data['book_id']), 403);
        $sheet = ResultSheet::query()->where('class_id', $class->id)->where('book_id', $data['book_id'])->where('academic_term_id', $data['academic_term_id'])->firstOrFail();

        $correction = $this->approvals->requestCorrection($sheet, $data, $request->user());

        return ApiResponse::created(['id' => $correction->id, 'status' => $correction->status]);
    }

    public function approveCorrection(Request $request, GradeCorrection $gradeCorrection): JsonResponse
    {
        $c = $this->approvals->approveCorrection($gradeCorrection, $request->user());

        return ApiResponse::success(['id' => $c->id, 'status' => $c->status]);
    }

    public function rejectCorrection(Request $request, GradeCorrection $gradeCorrection): JsonResponse
    {
        abort_unless($this->flow->mayDecide($gradeCorrection, $request->user(), 'reject'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $c = $this->approvals->rejectCorrection($gradeCorrection, $data['reason'], $request->user());

        return ApiResponse::success(['id' => $c->id, 'status' => $c->status]);
    }

    /** The requester takes back a correction nobody has decided yet. */
    public function destroyCorrection(Request $request, GradeCorrection $gradeCorrection): JsonResponse
    {
        abort_unless($gradeCorrection->status === GradeCorrection::STATUS_PENDING && (int) $gradeCorrection->requested_by === (int) $request->user()->getKey(), 403);
        $gradeCorrection->delete();

        return ApiResponse::noContent();
    }

    /** Every change made to an approved sheet: old → new, why, by whom. */
    public function history(Request $request): JsonResponse
    {
        $data = $request->validate([
            'class_id' => ['required', 'integer'],
            'book_id' => ['required', 'integer'],
            'academic_term_id' => ['required', 'integer'],
        ]);
        $class = SchoolClass::query()->findOrFail($data['class_id']);
        abort_unless($this->access->canView($request->user(), $class), 403);
        $sheet = ResultSheet::query()->where('class_id', $class->id)->where('book_id', $data['book_id'])->where('academic_term_id', $data['academic_term_id'])->first();
        if ($sheet === null) {
            return ApiResponse::success([]);
        }

        $rows = GradeEntryHistory::query()->where('result_sheet_id', $sheet->id)->orderByDesc('changed_at')->get();
        $students = Student::query()->whereIn('id', $rows->pluck('student_id')->unique()->all())->get()->keyBy('id');
        $components = AssessmentComponent::query()->whereIn('id', $rows->pluck('assessment_component_id')->filter()->unique()->all())->get()->keyBy('id');
        $criteria = CompetencyCriterion::query()->whereIn('id', $rows->pluck('competency_criterion_id')->filter()->unique()->all())->get()->keyBy('id');
        $levels = CompetencyLevel::query()->whereIn('id', $rows->pluck('old_level_id')->merge($rows->pluck('new_level_id'))->filter()->unique()->all())->get()->keyBy('id');
        $names = User::query()->whereIn('id', $rows->pluck('changed_by')->filter()->unique()->all())->pluck('name', 'id');

        return ApiResponse::success($rows->map(function (GradeEntryHistory $h) use ($students, $components, $criteria, $levels, $names) {
            $component = $components->get($h->assessment_component_id);

            return [
                'id' => $h->id,
                'student' => $students->get($h->student_id)?->fullName(),
                'what' => $component ? $component->name.($component->occurrences > 1 ? ' '.$h->occurrence : '') : $criteria->get($h->competency_criterion_id)?->name,
                'old' => $this->value($h->old_score, $h->old_absent, $levels->get($h->old_level_id)),
                'new' => $this->value($h->new_score, $h->new_absent, $levels->get($h->new_level_id)),
                'reason' => $h->reason,
                'correction' => $h->grade_correction_id ? 'GC-'.str_pad((string) $h->grade_correction_id, 6, '0', STR_PAD_LEFT) : null,
                'changed_by' => $names[$h->changed_by] ?? null,
                'changed_at' => $h->changed_at?->toIso8601String(),
            ];
        })->values());
    }

    /** A cell's value for display: "45", "A" (absent), a level code, or "—". */
    private function value(mixed $score, bool $absent, ?CompetencyLevel $level): string
    {
        if ($absent) {
            return 'A';
        }
        if ($level !== null) {
            return $level->code;
        }

        return $score === null ? '—' : rtrim(rtrim(number_format((float) $score, 2, '.', ''), '0'), '.');
    }

    private function canViewAll(User $user): bool
    {
        return $user->hasAnyPermission(Permissions::GRADEBOOK_MANAGE_ALL, Permissions::GRADEBOOK_APPROVE);
    }

    /** Sheets of classes this account teaches or is homeroom teacher of. */
    private function scopeToOwnClasses(Builder $query, User $user): Builder
    {
        return $query->whereIn('class_id', $this->access->classes($user)->modelKeys() ?: [0]);
    }
}
