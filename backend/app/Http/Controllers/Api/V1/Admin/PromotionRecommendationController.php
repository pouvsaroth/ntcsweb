<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\JobGrade;
use App\Models\JobLevel;
use App\Models\PerformanceReview;
use App\Models\Position;
use App\Models\PromotionRecommendation;
use App\Models\Staff;
use App\Models\StaffSalary;
use App\Services\Approvals\ApprovalFlow;
use App\Services\Performance\PromotionService;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * HRM > Performance Management > Promotion recommendation — recommend, see,
 * approve / reject and cancel promotions, plus what the form needs: the
 * staff member's current position / grade / level / salary, the options,
 * and the top scorers of a cycle as suggestions. See PromotionService.
 */
final class PromotionRecommendationController extends Controller
{
    private const WITH = ['staff', 'review.cycle', 'fromPosition', 'toPosition', 'fromJobGrade', 'toJobGrade', 'fromJobLevel', 'toJobLevel', 'requestedBy', 'decidedBy'];

    public function __construct(
        private readonly PromotionService $promotions,
        private readonly ApprovalFlow $flow,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PromotionRecommendation::class);

        $recommendations = ApiQuery::for(PromotionRecommendation::query()->with(self::WITH), $request)
            ->filterable(['status', 'staff_id'])
            ->sortable(['created_at', 'effective_date'], default: '-created_at')
            ->maxPerPage(100)
            ->paginate();

        $this->flow->attachProgress($recommendations->getCollection(), $request->user());

        return ApiResponse::success($recommendations->through(fn (PromotionRecommendation $r) => $this->row($r, $request)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', PromotionRecommendation::class);

        $data = $request->validate([
            'staff_id' => ['required', 'integer', Rule::exists('tenant.staff', 'id')],
            'performance_review_id' => ['nullable', 'integer', Rule::exists('tenant.performance_reviews', 'id')],
            'to_position_id' => ['nullable', 'integer', Rule::exists('tenant.positions', 'id')],
            'to_job_grade_id' => ['nullable', 'integer', Rule::exists('tenant.job_grades', 'id')],
            'to_job_level_id' => ['nullable', 'integer', Rule::exists('tenant.job_levels', 'id')],
            'new_basic_salary' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'effective_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:5000'],
        ]);

        $recommendation = $this->promotions->recommend(Staff::query()->findOrFail($data['staff_id']), $data, $request->user());

        return ApiResponse::created($this->detail($recommendation, $request));
    }

    public function approve(Request $request, PromotionRecommendation $promotionRecommendation): JsonResponse
    {
        return ApiResponse::success($this->detail($this->promotions->approve($promotionRecommendation, $request->user()), $request));
    }

    public function reject(Request $request, PromotionRecommendation $promotionRecommendation): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return ApiResponse::success($this->detail($this->promotions->reject($promotionRecommendation, $data['reason'], $request->user()), $request));
    }

    public function cancel(Request $request, PromotionRecommendation $promotionRecommendation): JsonResponse
    {
        $this->authorize('update', $promotionRecommendation);

        return ApiResponse::success($this->detail($this->promotions->cancel($promotionRecommendation), $request));
    }

    /** Positions, job grades and job levels to promote into. */
    public function options(): JsonResponse
    {
        $this->authorize('viewAny', PromotionRecommendation::class);

        $pick = fn ($rows) => $rows->map(fn ($row) => ['id' => $row->id, 'name' => $row->name])->values();

        return ApiResponse::success([
            'positions' => $pick(Position::query()->where('status', 'active')->orderBy('name')->get()),
            'job_grades' => $pick(JobGrade::query()->where('is_active', true)->orderBy('code')->get()),
            'job_levels' => $pick(JobLevel::query()->where('is_active', true)->orderBy('code')->get()),
        ]);
    }

    /** What a staff member has now — shown on the form as "from". */
    public function current(Staff $staff): JsonResponse
    {
        $this->authorize('viewAny', PromotionRecommendation::class);

        $staff->load(['position', 'jobGrade', 'jobLevel', 'department']);
        $salary = StaffSalary::query()->where('staff_id', $staff->id)->effectiveOn(now()->toDateString())->first();
        $review = PerformanceReview::query()->where('staff_id', $staff->id)->where('status', PerformanceReview::STATUS_COMPLETED)->with('cycle')->latest('manager_submitted_at')->first();

        return ApiResponse::success([
            'staff' => ['id' => $staff->id, 'name' => $staff->fullName(), 'employee_code' => $staff->employee_code, 'department' => $staff->department?->name],
            'position' => $staff->position !== null ? ['id' => $staff->position->id, 'name' => $staff->position->name] : null,
            'job_grade' => $staff->jobGrade !== null ? ['id' => $staff->jobGrade->id, 'name' => $staff->jobGrade->name] : null,
            'job_level' => $staff->jobLevel !== null ? ['id' => $staff->jobLevel->id, 'name' => $staff->jobLevel->name] : null,
            'basic_salary' => $salary !== null ? (float) $salary->basic_salary : null,
            'currency' => $salary?->currency,
            'latest_review' => $review !== null ? ['id' => $review->id, 'cycle' => $review->cycle?->name, 'final_score' => $review->final_score] : null,
        ]);
    }

    /**
     * A cycle's completed reviews scoring at least `min_score` (default 4)
     * whose staff member has no open recommendation — who to consider.
     */
    public function suggestions(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PromotionRecommendation::class);

        $data = $request->validate([
            'performance_cycle_id' => ['required', 'integer', Rule::exists('tenant.performance_cycles', 'id')],
            'min_score' => ['sometimes', 'numeric', 'between:1,5'],
        ]);

        $busy = PromotionRecommendation::query()->whereIn('status', [PromotionRecommendation::STATUS_PENDING, PromotionRecommendation::STATUS_APPROVED])->pluck('staff_id');
        $reviews = PerformanceReview::query()
            ->where('performance_cycle_id', $data['performance_cycle_id'])
            ->where('status', PerformanceReview::STATUS_COMPLETED)
            ->where('final_score', '>=', (float) ($data['min_score'] ?? 4))
            ->whereNotIn('staff_id', $busy)
            ->with(['staff.position'])
            ->orderByDesc('final_score')
            ->get();

        return ApiResponse::success($reviews->map(fn (PerformanceReview $r) => [
            'review_id' => $r->id,
            'staff' => ['id' => $r->staff?->id, 'name' => $r->staff?->fullName(), 'employee_code' => $r->staff?->employee_code, 'position' => $r->staff?->position?->name],
            'final_score' => $r->final_score,
        ])->values());
    }

    /** @return array<string, mixed> */
    private function detail(PromotionRecommendation $recommendation, Request $request): array
    {
        $recommendation->load(self::WITH);
        $this->flow->attachProgress([$recommendation], $request->user());

        return $this->row($recommendation, $request);
    }

    /** @return array<string, mixed> */
    private function row(PromotionRecommendation $r, Request $request): array
    {
        $name = fn ($model) => $model !== null ? ['id' => $model->id, 'name' => $model->name] : null;

        return [
            'id' => $r->id,
            'staff' => $r->staff !== null ? ['id' => $r->staff->id, 'name' => $r->staff->fullName(), 'employee_code' => $r->staff->employee_code] : null,
            'review' => $r->review !== null ? ['id' => $r->review->id, 'cycle' => $r->review->cycle?->name, 'final_score' => $r->review->final_score] : null,
            'from_position' => $name($r->fromPosition),
            'to_position' => $name($r->toPosition),
            'from_job_grade' => $name($r->fromJobGrade),
            'to_job_grade' => $name($r->toJobGrade),
            'from_job_level' => $name($r->fromJobLevel),
            'to_job_level' => $name($r->toJobLevel),
            'from_basic_salary' => $r->from_basic_salary,
            'new_basic_salary' => $r->new_basic_salary,
            'salary_currency' => $r->salary_currency,
            'effective_date' => $r->effective_date?->toDateString(),
            'reason' => $r->reason,
            'status' => $r->status,
            'requested_by' => $r->requestedBy?->name,
            'decided_by' => $r->decidedBy?->name,
            'decided_at' => $r->decided_at?->toIso8601String(),
            'decision_reason' => $r->decision_reason,
            'applied_at' => $r->applied_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String(),
            'approval_flow' => $r->relationLoaded('approvalFlow') ? $r->getRelation('approvalFlow') : null,
            'can_decide' => $r->status === PromotionRecommendation::STATUS_PENDING && $this->flow->mayDecide($r, $request->user(), 'approve'),
        ];
    }
}
