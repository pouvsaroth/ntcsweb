<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Applicant;
use App\Models\Interview;
use App\Models\InterviewEvaluation;
use App\Models\OfferLetter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HRM > Recruitment > Candidate selection: one job's candidates side by
 * side — their interviews, average evaluation score and how the
 * interviewers voted — best first, so HR can pick who gets an offer.
 */
final class CandidateSelectionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Applicant::class);

        $request->validate(['job_position_id' => ['required', 'integer']]);

        $applicants = Applicant::query()
            ->where('job_position_id', $request->integer('job_position_id'))
            ->whereNotIn('stage', ['withdrawn'])
            ->with(['documents:id,applicant_id,type'])
            ->get();

        $evaluations = InterviewEvaluation::query()
            ->whereHas('interview', fn (Builder $interview) => $interview->whereIn('applicant_id', $applicants->modelKeys()))
            ->with('interview:id,applicant_id')
            ->get()
            ->groupBy(fn (InterviewEvaluation $evaluation) => $evaluation->interview->applicant_id);

        $interviewCounts = Interview::query()
            ->whereIn('applicant_id', $applicants->modelKeys())
            ->whereNot('status', 'cancelled')
            ->selectRaw('applicant_id, count(*) as total')
            ->groupBy('applicant_id')
            ->pluck('total', 'applicant_id');

        $offers = OfferLetter::query()->whereIn('applicant_id', $applicants->modelKeys())->latest('id')->get()->unique('applicant_id')->keyBy('applicant_id');

        $rows = $applicants->map(function (Applicant $applicant) use ($evaluations, $interviewCounts, $offers) {
            $mine = $evaluations->get($applicant->id, collect());
            $offer = $offers->get($applicant->id);

            return [
                'id' => $applicant->id,
                'name' => $applicant->fullName(),
                'phone' => $applicant->phone,
                'stage' => $applicant->stage,
                'source' => $applicant->source,
                'expected_salary' => $applicant->expected_salary !== null ? (float) $applicant->expected_salary : null,
                'applied_on' => $applicant->created_at?->toIso8601String(),
                'interviews' => (int) ($interviewCounts[$applicant->id] ?? 0),
                'evaluations' => $mine->count(),
                'average_score' => $mine->isEmpty() ? null : round((float) $mine->avg('overall_score'), 2),
                'votes' => [
                    'hire' => $mine->where('recommendation', 'hire')->count(),
                    'maybe' => $mine->where('recommendation', 'maybe')->count(),
                    'no_hire' => $mine->where('recommendation', 'no_hire')->count(),
                ],
                'offer' => $offer !== null ? ['id' => $offer->id, 'reference' => $offer->reference(), 'status' => $offer->status] : null,
            ];
        });

        // Best average first; not yet evaluated last, newest applicant first among them.
        $sorted = $rows->sort(function (array $a, array $b) {
            if ($a['average_score'] === null && $b['average_score'] === null) {
                return strcmp((string) $b['applied_on'], (string) $a['applied_on']);
            }
            if ($a['average_score'] === null) {
                return 1;
            }
            if ($b['average_score'] === null) {
                return -1;
            }

            return $b['average_score'] <=> $a['average_score'] ?: $b['votes']['hire'] <=> $a['votes']['hire'];
        })->values();

        return ApiResponse::success($sorted);
    }
}
