<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Classroom;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Classroom
 */
class ClassroomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'capacity' => $this->capacity,
            'location' => $this->location,
            'building_id' => $this->building_id,
            'building' => $this->whenLoaded('building', fn () => [
                'id' => $this->building->id,
                'name' => $this->building->name,
            ]),
            'status' => $this->status,
            'classes_count' => $this->whenCounted('classes'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
