<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\JobPosting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JobPosting
 */
class JobPostingResource extends JsonResource
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
                'status' => $this->jobPosition->status,
            ] : null),
            'channel' => $this->channel,
            'url' => $this->url,
            'posted_on' => $this->posted_on?->toDateString(),
            'expires_on' => $this->expires_on?->toDateString(),
            'is_active' => $this->is_active,
            'note' => $this->note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
