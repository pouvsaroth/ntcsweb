<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ClassroomTable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClassroomTable
 */
class ClassroomTableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'classroom_id' => $this->classroom_id,
            'classroom' => $this->whenLoaded('classroom', fn () => $this->classroom !== null ? [
                'id' => $this->classroom->id,
                'name' => $this->classroom->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
