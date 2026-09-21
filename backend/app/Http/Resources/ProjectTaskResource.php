<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ProjectTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProjectTask
 */
class ProjectTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_column_id' => $this->project_column_id,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority,
            'start_date' => $this->start_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'estimated_hours' => $this->estimated_hours !== null ? (float) $this->estimated_hours : null,
            'order' => $this->order,
            'assignee_id' => $this->assignee_id,
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee?->name),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'project_milestone_id' => $this->project_milestone_id,
            'milestone' => $this->whenLoaded('milestone', fn () => $this->milestone === null ? null : [
                'id' => $this->milestone->id,
                'name' => $this->milestone->name,
            ]),
            'labels' => ProjectLabelResource::collection($this->whenLoaded('labels')),
            'checklist_items' => ProjectTaskChecklistItemResource::collection($this->whenLoaded('checklistItems')),
            'checklist_progress' => $this->when($this->relationLoaded('checklistItems'), fn () => [
                'completed' => $this->checklistItems->where('is_completed', true)->count(),
                'total' => $this->checklistItems->count(),
            ]),
            'attachments' => ProjectTaskAttachmentResource::collection($this->whenLoaded('attachments')),
            'dependencies' => ProjectTaskSummaryResource::collection($this->whenLoaded('dependencies')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
