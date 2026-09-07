<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ProjectColumn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProjectColumn
 */
class ProjectColumnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'color' => $this->color,
            'order' => $this->order,
            'tasks' => ProjectTaskResource::collection($this->whenLoaded('tasks')),
        ];
    }
}
