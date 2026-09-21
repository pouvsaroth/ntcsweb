<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ProjectTaskChecklistItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProjectTaskChecklistItem
 */
class ProjectTaskChecklistItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'is_completed' => $this->is_completed,
            'order' => $this->order,
        ];
    }
}
