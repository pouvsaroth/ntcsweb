<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\InterviewEvaluationRequest;
use App\Http\Resources\InterviewEvaluationResource;
use App\Http\Responses\ApiResponse;
use App\Models\Interview;
use App\Models\InterviewEvaluation;
use App\Support\Authorization\Permissions;
use App\Support\Query\ApiQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HRM > Recruitment > Interview evaluation. Anyone with recruitment access
 * reads them; an interviewer writes (and may revise) their own — someone
 * not on the interview needs recruitment.update to record one for them.
 */
final class InterviewEvaluationController extends Controller
{
    private const RELATIONS = ['interview.applicant.jobPosition', 'evaluator'];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InterviewEvaluation::class);

        $query = InterviewEvaluation::query()->with(self::RELATIONS);

        if ($request->filled('applicant_id')) {
            $query->whereHas('interview', fn (Builder $interview) => $interview->where('applicant_id', $request->integer('applicant_id')));
        }

        if ($request->filled('job_position_id')) {
            $query->whereHas('interview.applicant', fn (Builder $applicant) => $applicant->where('job_position_id', $request->integer('job_position_id')));
        }

        $evaluations = ApiQuery::for($query, $request)
            ->filterable(['interview_id', 'recommendation', 'evaluator_id'])
            ->sortable(['overall_score', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(InterviewEvaluationResource::collection($evaluations));
    }

    /** Create, or replace the evaluator's own earlier one for the same interview. */
    public function store(InterviewEvaluationRequest $request): JsonResponse
    {
        $user = $request->user();
        $interview = Interview::query()->with('interviewerRows')->findOrFail($request->validated('interview_id'));

        abort_unless($interview->isInterviewer($user) || $user->hasPermission(Permissions::RECRUITMENT_UPDATE), 403, 'Only an interviewer on this interview can evaluate it.');

        if ($interview->status === 'cancelled') {
            return ApiResponse::error('This interview was cancelled.', 422);
        }

        $scores = collect(InterviewEvaluation::CRITERIA)->mapWithKeys(fn (string $criterion) => [$criterion => (int) $request->validated("scores.{$criterion}")])->all();

        $evaluation = InterviewEvaluation::query()->updateOrCreate(
            ['interview_id' => $interview->id, 'evaluator_id' => $user->getKey()],
            [
                'scores' => $scores,
                'overall_score' => round(array_sum($scores) / count($scores), 2),
                'recommendation' => $request->validated('recommendation'),
                'strengths' => $request->validated('strengths'),
                'concerns' => $request->validated('concerns'),
            ],
        );

        return ApiResponse::success(new InterviewEvaluationResource($evaluation->load(self::RELATIONS)), status: $evaluation->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, InterviewEvaluation $interviewEvaluation): JsonResponse
    {
        $user = $request->user();
        abort_unless((int) $interviewEvaluation->evaluator_id === (int) $user->getKey() || $user->hasPermission(Permissions::RECRUITMENT_DELETE), 403);

        $interviewEvaluation->delete();

        return ApiResponse::noContent();
    }
}
