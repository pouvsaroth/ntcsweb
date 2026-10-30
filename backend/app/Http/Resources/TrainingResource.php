<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Training;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Training
 */
class TrainingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'category' => $this->category,
            'provider_type' => $this->provider_type,
            'trainer' => $this->trainer,
            'mode' => $this->mode,
            'location' => $this->location,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'start_time' => $this->start_time ? substr((string) $this->start_time, 0, 5) : null,
            'end_time' => $this->end_time ? substr((string) $this->end_time, 0, 5) : null,
            'hours' => $this->hours !== null ? (float) $this->hours : null,
            'cost' => $this->cost !== null ? (float) $this->cost : null,
            'description' => $this->description,
            'status' => $this->status,
            'participants_count' => $this->whenCounted('participants'),
            'attended_count' => $this->when(isset($this->attended_count), fn () => (int) $this->attended_count),
            'participants' => $this->whenLoaded('participants', fn () => TrainingParticipantResource::collection($this->participants)->resolve($request)),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
