<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'task_count' => $this->whenCounted('tasks'),
            'columns' => ProjectColumnResource::collection($this->whenLoaded('columns')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
