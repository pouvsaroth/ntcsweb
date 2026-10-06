<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\JobPosition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JobPosition
 */
class JobPositionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference(),
            'manpower_request_id' => $this->manpower_request_id,
            'manpower_request' => $this->whenLoaded('manpowerRequest', fn () => $this->manpowerRequest?->reference()),
            'title' => $this->title,
            'department_id' => $this->department_id,
            'department' => $this->whenLoaded('department', fn () => $this->department?->name),
            'position_id' => $this->position_id,
            'position' => $this->whenLoaded('position', fn () => $this->position?->name),
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'headcount' => $this->headcount,
            'employment_type' => $this->employment_type,
            'salary_min' => $this->salary_min !== null ? (float) $this->salary_min : null,
            'salary_max' => $this->salary_max !== null ? (float) $this->salary_max : null,
            'salary_currency' => $this->salary_currency,
            'description' => $this->description,
            'requirements' => $this->requirements,
            'status' => $this->status,
            'opened_on' => $this->opened_on?->toDateString(),
            'closes_on' => $this->closes_on?->toDateString(),
            'postings_count' => $this->whenCounted('postings'),
            'on_careers_page' => $this->when(isset($this->on_careers_page), fn () => (bool) $this->on_careers_page),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
