<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Applicant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Applicant
 */
class ApplicantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'job_position_id' => $this->job_position_id,
            'job_position' => $this->whenLoaded('jobPosition', fn () => $this->jobPosition !== null ? [
                'id' => $this->jobPosition->id,
                'reference' => $this->jobPosition->reference(),
                'title' => $this->jobPosition->title,
            ] : null),
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),
            'gender' => $this->gender,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'source' => $this->source,
            'expected_salary' => $this->expected_salary !== null ? (float) $this->expected_salary : null,
            'available_from' => $this->available_from?->toDateString(),
            'cover_letter' => $this->cover_letter,
            'stage' => $this->stage,
            'notes' => $this->notes,
            'documents_count' => $this->whenCounted('documents'),
            'documents' => ApplicantDocumentResource::collection($this->whenLoaded('documents')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
