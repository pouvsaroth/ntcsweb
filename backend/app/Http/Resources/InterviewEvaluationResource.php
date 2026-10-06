<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\InterviewEvaluation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InterviewEvaluation
 */
class InterviewEvaluationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'interview_id' => $this->interview_id,
            'interview' => $this->whenLoaded('interview', fn () => $this->interview !== null ? [
                'id' => $this->interview->id,
                'round' => $this->interview->round,
                'scheduled_at' => $this->interview->scheduled_at?->toIso8601String(),
                'applicant' => $this->interview->relationLoaded('applicant') && $this->interview->applicant !== null ? [
                    'id' => $this->interview->applicant->id,
                    'name' => $this->interview->applicant->fullName(),
                    'job_title' => $this->interview->applicant->relationLoaded('jobPosition') ? $this->interview->applicant->jobPosition?->title : null,
                ] : null,
            ] : null),
            'evaluator_id' => $this->evaluator_id,
            'evaluator' => $this->whenLoaded('evaluator', fn () => $this->evaluator?->name),
            'scores' => $this->scores,
            'overall_score' => (float) $this->overall_score,
            'recommendation' => $this->recommendation,
            'strengths' => $this->strengths,
            'concerns' => $this->concerns,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
