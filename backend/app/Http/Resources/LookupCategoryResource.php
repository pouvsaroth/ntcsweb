<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LookupCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LookupCategory
 */
class LookupCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'values_count' => $this->whenCounted('values'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
