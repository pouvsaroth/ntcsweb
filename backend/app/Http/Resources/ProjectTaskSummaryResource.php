<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ProjectTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A compact view of a card, for contexts that reference another card without
 * needing its full detail — dependency lists, in particular — where nesting
 * a full ProjectTaskResource would pull in that card's own labels/checklist/
 * attachments/dependencies for no reason.
 *
 * @mixin ProjectTask
 */
class ProjectTaskSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'project_column_id' => $this->project_column_id,
            'priority' => $this->priority,
        ];
    }
}
