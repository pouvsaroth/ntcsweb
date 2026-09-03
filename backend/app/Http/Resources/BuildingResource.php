<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Building;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Building
 */
class BuildingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'address' => $this->address,
            'status' => $this->status,
            'classrooms_count' => $this->whenCounted('classrooms'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
