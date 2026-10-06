<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Interview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Interview
 */
class InterviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'applicant_id' => $this->applicant_id,
            'applicant' => $this->whenLoaded('applicant', fn () => $this->applicant !== null ? [
                'id' => $this->applicant->id,
                'name' => $this->applicant->fullName(),
                'phone' => $this->applicant->phone,
                'stage' => $this->applicant->stage,
                'job_title' => $this->applicant->relationLoaded('jobPosition') ? $this->applicant->jobPosition?->title : null,
            ] : null),
            'round' => $this->round,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'duration_minutes' => $this->duration_minutes,
            'mode' => $this->mode,
            'location' => $this->location,
            'status' => $this->status,
            'notes' => $this->notes,
            'interviewers' => $this->whenLoaded('interviewerRows', fn () => $this->interviewers()->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()),
            'evaluations_count' => $this->whenLoaded('evaluations', fn () => $this->evaluations->count()),
            'average_score' => $this->whenLoaded('evaluations', fn () => $this->evaluations->isEmpty() ? null : round((float) $this->evaluations->avg('overall_score'), 2)),
            // Whether the signed-in user sits on it, and has already evaluated it.
            'is_my_interview' => $this->whenLoaded('interviewerRows', fn () => $user !== null && $this->isInterviewer($user)),
            'my_evaluation_id' => $this->whenLoaded('evaluations', fn () => $user !== null ? $this->evaluations->firstWhere('evaluator_id', (int) $user->getKey())?->id : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
