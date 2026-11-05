<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ExpenseEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ExpenseEntity
 */
class ExpenseEntityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'expenses_count' => $this->whenCounted('expenses'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
