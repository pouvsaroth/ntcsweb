<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AcademicProgram;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AcademicProgram
 */
class AcademicProgramResource extends JsonResource
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
            'books' => BookResource::collection($this->whenLoaded('books')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
