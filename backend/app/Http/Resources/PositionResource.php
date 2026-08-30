<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Position;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Position
 */
class PositionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'role' => new RoleResource($this->whenLoaded('role')),
            'staff_count' => $this->whenCounted('staff'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
