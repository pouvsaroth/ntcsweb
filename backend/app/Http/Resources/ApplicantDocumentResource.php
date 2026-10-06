<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ApplicantDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * No URL on purpose — the file is private; it's fetched through
 * GET applicant-documents/{id}/download.
 *
 * @mixin ApplicantDocument
 */
class ApplicantDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'applicant_id' => $this->applicant_id,
            'applicant' => $this->whenLoaded('applicant', fn () => $this->applicant !== null ? [
                'id' => $this->applicant->id,
                'name' => $this->applicant->fullName(),
                'job_title' => $this->applicant->relationLoaded('jobPosition') ? $this->applicant->jobPosition?->title : null,
            ] : null),
            'type' => $this->type,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'uploaded_by' => $this->whenLoaded('uploadedBy', fn () => $this->uploadedBy?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
