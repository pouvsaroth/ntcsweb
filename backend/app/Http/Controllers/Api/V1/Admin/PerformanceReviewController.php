<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PerformanceReviewPresenter;
use App\Http\Responses\ApiResponse;
use App\Models\PerformanceCycle;
use App\Models\PerformanceReview;
use App\Models\Staff;
use App\Services\Performance\PerformanceReviewService;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * HRM > Performance Management > Performance review / Performance score —
 * HR's side of reviews: launch a cycle's reviews, watch them, change a
 * reviewer or the KPIs, move one on, and the cycle's scores. See
 * PerformanceReviewService.
 */
final class PerformanceReviewController extends Controller
{
    private const WITH = ['cycle', 'staff.position', 'staff.department', 'reviewer'];

    public function __construct(
        private readonly PerformanceReviewService $reviews,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PerformanceReview::class);

        $query = PerformanceReview::query()
            ->with(self::WITH)
            ->when($request->filled('search'), fn ($q) => $q->whereHas('staff', fn ($s) => $s->where(fn ($w) => $w
                ->where('first_name', 'ilike', '%'.$request->string('search').'%')
                ->orWhere('last_name', 'ilike', '%'.$request->string('search').'%')
                ->orWhere('employee_code', 'ilike', '%'.$request->string('search').'%'))));

        $reviews = ApiQuery::for($query, $request)
            ->filterable(['performance_cycle_id', 'status', 'reviewer_staff_id'])
            ->sortable(['created_at', 'final_score'], default: '-created_at')
            ->maxPerPage(200)
            ->paginate();

        return ApiResponse::success($reviews->through(fn (PerformanceReview $review) => PerformanceReviewPresenter::row($review)));
    }

    /** Working staff not yet in the cycle — who a launch can add — with who they report to. */
    public function candidates(PerformanceCycle $performanceCycle): JsonResponse
    {
        $this->authorize('viewAny', PerformanceReview::class);

        $already = PerformanceReview::query()->where('performance_cycle_id', $performanceCycle->id)->pluck('staff_id');
        $staff = Staff::query()
            ->whereIn('status', Staff::STATUSES_WORKING)
            ->whereNotIn('id', $already)
            ->with(['reportsTo', 'department', 'position'])
            ->orderBy('employee_code')
            ->get();

        return ApiResponse::success($staff->map(fn (Staff $s) => [
            'id' => $s->id,
            'name' => $s->fullName(),
            'employee_code' => $s->employee_code,
            'department' => $s->department?->name,
            'position' => $s->position?->name,
            'reports_to' => $s->reportsTo !== null ? ['id' => $s->reportsTo->id, 'name' => $s->reportsTo->fullName()] : null,
        ])->values());
    }

    public function launch(Request $request, PerformanceCycle $performanceCycle): JsonResponse
    {
        $this->authorize('create', PerformanceReview::class);

        $data = $request->validate([
            'staff_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'staff_ids.*' => ['integer', Rule::exists('tenant.staff', 'id')],
        ]);

        $created = $this->reviews->launch($performanceCycle, array_map('intval', $data['staff_ids']), $request->user());

        return ApiResponse::created(['launched' => $created->count()]);
    }

    public function show(PerformanceReview $performanceReview): JsonResponse
    {
        $this->authorize('view', $performanceReview);

        return ApiResponse::success(PerformanceReviewPresenter::detail($performanceReview->load(self::WITH), 'hr'));
    }

    /** HR: change who assesses it. */
    public function update(Request $request, PerformanceReview $performanceReview): JsonResponse
    {
        $this->authorize('update', $performanceReview);

        $data = $request->validate([
            'reviewer_staff_id' => ['nullable', 'integer', Rule::exists('tenant.staff', 'id'), Rule::notIn([$performanceReview->staff_id])],
        ]);
        $performanceReview->update($data);

        return ApiResponse::success(PerformanceReviewPresenter::detail($performanceReview->fresh()->load(self::WITH), 'hr'));
    }

    /** HR: the review's KPIs — `kpis` replaces the list (rows sent back with their id keep their ratings). */
    public function replaceKpis(Request $request, PerformanceReview $performanceReview): JsonResponse
    {
        $this->authorize('update', $performanceReview);
        if ($performanceReview->status === PerformanceReview::STATUS_COMPLETED) {
            throw ValidationException::withMessages(['status' => 'Reopen the review to change its KPIs.']);
        }

        $data = $request->validate([
            'kpis' => ['present', 'array', 'max:50'],
            'kpis.*.id' => ['nullable', 'integer'],
            'kpis.*.name' => ['required', 'string', 'max:255'],
            'kpis.*.measurement' => ['nullable', 'string', 'max:255'],
            'kpis.*.unit' => ['nullable', 'string', 'max:20'],
            'kpis.*.target' => ['nullable', 'numeric'],
            'kpis.*.higher_is_better' => ['sometimes', 'boolean'],
            'kpis.*.weight' => ['sometimes', 'integer', 'min:0', 'max:100'],
        ]);

        $rows = $data['kpis'];
        // validate() rebuilds the list rule by rule — back into the order sent.
        ksort($rows);

        DB::connection('tenant')->transaction(function () use ($performanceReview, $rows) {
            $keep = collect($rows)->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
            $performanceReview->kpis()->getQuery()->whereNotIn('id', $keep)->delete();
            foreach (array_values($rows) as $order => $row) {
                $attributes = collect($row)->only(['name', 'measurement', 'unit', 'target', 'higher_is_better', 'weight'])->all() + ['sort_order' => $order];
                $existing = isset($row['id']) ? $performanceReview->kpis()->getQuery()->whereKey($row['id'])->first() : null;
                $existing !== null ? $existing->update($attributes) : $performanceReview->kpis()->create($attributes);
            }
        });

        return ApiResponse::success(PerformanceReviewPresenter::detail($performanceReview->fresh()->load(self::WITH), 'hr'));
    }

    public function sendToManager(PerformanceReview $performanceReview): JsonResponse
    {
        $this->authorize('update', $performanceReview);

        return ApiResponse::success(PerformanceReviewPresenter::detail($this->reviews->sendToManager($performanceReview)->load(self::WITH), 'hr'));
    }

    public function reopen(PerformanceReview $performanceReview): JsonResponse
    {
        $this->authorize('update', $performanceReview);

        return ApiResponse::success(PerformanceReviewPresenter::detail($this->reviews->reopen($performanceReview)->load(self::WITH), 'hr'));
    }

    public function destroy(PerformanceReview $performanceReview): JsonResponse
    {
        $this->authorize('delete', $performanceReview);

        if ($performanceReview->status === PerformanceReview::STATUS_COMPLETED) {
            return ApiResponse::error('A completed review cannot be deleted. Reopen it first if it needs changing.', 422);
        }

        $performanceReview->delete();

        return ApiResponse::noContent();
    }

    /**
     * A cycle's completed reviews, best final score first, with the cycle's
     * averages and how the scores spread across the 1–5 bands.
     */
    public function scores(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PerformanceReview::class);

        $data = $request->validate(['performance_cycle_id' => ['required', 'integer', Rule::exists('tenant.performance_cycles', 'id')]]);

        $all = PerformanceReview::query()->where('performance_cycle_id', $data['performance_cycle_id'])->with(self::WITH)->get();
        $completed = $all->where('status', PerformanceReview::STATUS_COMPLETED)->sortByDesc('final_score')->values();

        $bands = collect([5, 4, 3, 2, 1])->mapWithKeys(fn (int $band) => [$band => $completed->filter(fn (PerformanceReview $r) => $r->final_score !== null && (int) min(5, max(1, round($r->final_score))) === $band)->count()]);

        return ApiResponse::success([
            'counts' => [
                'total' => $all->count(),
                'self_assessment' => $all->where('status', PerformanceReview::STATUS_SELF)->count(),
                'manager_assessment' => $all->where('status', PerformanceReview::STATUS_MANAGER)->count(),
                'completed' => $completed->count(),
            ],
            'averages' => [
                'final_score' => $completed->avg('final_score') !== null ? round((float) $completed->avg('final_score'), 2) : null,
                'kpi_score' => $completed->avg('kpi_score') !== null ? round((float) $completed->avg('kpi_score'), 2) : null,
                'goal_score' => $completed->avg('goal_score') !== null ? round((float) $completed->avg('goal_score'), 2) : null,
                'manager_score' => $completed->avg('manager_score') !== null ? round((float) $completed->avg('manager_score'), 2) : null,
                'self_score' => $completed->avg('self_score') !== null ? round((float) $completed->avg('self_score'), 2) : null,
            ],
            'bands' => $bands,
            'rows' => $completed->map(fn (PerformanceReview $review) => PerformanceReviewPresenter::row($review))->values(),
        ]);
    }
}
