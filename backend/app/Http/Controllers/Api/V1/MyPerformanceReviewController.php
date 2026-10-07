<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PerformanceReviewPresenter;
use App\Http\Responses\ApiResponse;
use App\Models\PerformanceReview;
use App\Models\Staff;
use App\Services\Performance\PerformanceReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Self-service — identity-gated through `$user->staff`, no permission
 * checked, same pattern as MyStaffAttendanceController:
 *
 * - my-performance-reviews: the signed-in staff member's own reviews
 *   (Employee self-assessment tab).
 * - team-performance-reviews: the reviews they are the manager on
 *   (Manager assessment tab).
 *
 * Someone else's review 404s, as if it didn't exist.
 */
final class MyPerformanceReviewController extends Controller
{
    private const WITH = ['cycle', 'staff.position', 'staff.department', 'reviewer'];

    public function __construct(
        private readonly PerformanceReviewService $reviews,
    ) {}

    // --- Self ----------------------------------------------------------------------------

    public function mine(Request $request): JsonResponse
    {
        // An account with no staff record (e.g. a school admin) just has none.
        $staff = $request->user()?->staff;
        if ($staff === null) {
            return ApiResponse::success([]);
        }

        $reviews = PerformanceReview::query()->where('staff_id', $staff->id)->with(self::WITH)->latest()->get();

        return ApiResponse::success($reviews->map(fn (PerformanceReview $r) => $this->listRow($r, 'self'))->values());
    }

    public function showMine(Request $request, int $review): JsonResponse
    {
        return ApiResponse::success(PerformanceReviewPresenter::detail($this->ownReview($request, $review), 'self'));
    }

    public function saveMine(Request $request, int $review): JsonResponse
    {
        $model = $this->ownReview($request, $review);
        $data = $request->validate([
            'kpis' => ['sometimes', 'array'],
            'kpis.*.id' => ['required', 'integer'],
            'kpis.*.actual' => ['nullable', 'numeric'],
            'kpis.*.self_rating' => ['nullable', 'integer', 'between:1,5'],
            'goals' => ['sometimes', 'array'],
            'goals.*.id' => ['required', 'integer'],
            'goals.*.self_rating' => ['nullable', 'integer', 'between:1,5'],
            'goals.*.progress' => ['sometimes', 'integer', 'between:0,100'],
            'answers' => ['sometimes', 'array'],
            'answers.*.id' => ['required', 'integer'],
            'answers.*.self_rating' => ['nullable', 'integer', 'between:1,5'],
            'answers.*.self_answer' => ['nullable', 'string', 'max:5000'],
            'self_comment' => ['nullable', 'string', 'max:5000'],
        ]);

        return ApiResponse::success(PerformanceReviewPresenter::detail($this->reviews->saveSelf($model, $data)->load(self::WITH), 'self'));
    }

    public function submitMine(Request $request, int $review): JsonResponse
    {
        return ApiResponse::success(PerformanceReviewPresenter::detail($this->reviews->submitSelf($this->ownReview($request, $review))->load(self::WITH), 'self'));
    }

    // --- Manager -------------------------------------------------------------------------------

    public function team(Request $request): JsonResponse
    {
        $staff = $request->user()?->staff;
        if ($staff === null) {
            return ApiResponse::success([]);
        }

        $reviews = PerformanceReview::query()->where('reviewer_staff_id', $staff->id)->with(self::WITH)->latest()->get();

        return ApiResponse::success($reviews->map(fn (PerformanceReview $r) => $this->listRow($r, 'manager'))->values());
    }

    public function showTeam(Request $request, int $review): JsonResponse
    {
        return ApiResponse::success(PerformanceReviewPresenter::detail($this->teamReview($request, $review), 'manager'));
    }

    public function saveTeam(Request $request, int $review): JsonResponse
    {
        $model = $this->teamReview($request, $review);
        $data = $request->validate([
            'kpis' => ['sometimes', 'array'],
            'kpis.*.id' => ['required', 'integer'],
            'kpis.*.actual' => ['nullable', 'numeric'],
            'kpis.*.manager_rating' => ['nullable', 'integer', 'between:1,5'],
            'kpis.*.comment' => ['nullable', 'string', 'max:2000'],
            'goals' => ['sometimes', 'array'],
            'goals.*.id' => ['required', 'integer'],
            'goals.*.manager_rating' => ['nullable', 'integer', 'between:1,5'],
            'goals.*.progress' => ['sometimes', 'integer', 'between:0,100'],
            'answers' => ['sometimes', 'array'],
            'answers.*.id' => ['required', 'integer'],
            'answers.*.manager_rating' => ['nullable', 'integer', 'between:1,5'],
            'answers.*.manager_answer' => ['nullable', 'string', 'max:5000'],
            'manager_comment' => ['nullable', 'string', 'max:5000'],
            'manager_overall_rating' => ['nullable', 'integer', 'between:1,5'],
        ]);

        return ApiResponse::success(PerformanceReviewPresenter::detail($this->reviews->saveManager($model, $data)->load(self::WITH), 'manager'));
    }

    public function submitTeam(Request $request, int $review): JsonResponse
    {
        return ApiResponse::success(PerformanceReviewPresenter::detail($this->reviews->submitManager($this->teamReview($request, $review))->load(self::WITH), 'manager'));
    }

    // --- Internals ------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function listRow(PerformanceReview $review, string $view): array
    {
        $row = PerformanceReviewPresenter::row($review);
        // A staff member sees their scores once it's completed.
        if ($view === 'self' && $review->status !== PerformanceReview::STATUS_COMPLETED) {
            foreach (['kpi_score', 'goal_score', 'manager_score', 'final_score'] as $score) {
                $row[$score] = null;
            }
        }

        return $row;
    }

    private function ownReview(Request $request, int $id): PerformanceReview
    {
        return PerformanceReview::query()->where('staff_id', $this->staffOrFail($request)->id)->with(self::WITH)->findOrFail($id);
    }

    private function teamReview(Request $request, int $id): PerformanceReview
    {
        return PerformanceReview::query()->where('reviewer_staff_id', $this->staffOrFail($request)->id)->with(self::WITH)->findOrFail($id);
    }

    private function staffOrFail(Request $request): Staff
    {
        $staff = $request->user()?->staff;

        if ($staff === null) {
            throw ValidationException::withMessages(['staff' => 'This account is not linked to a staff record.']);
        }

        return $staff;
    }
}
