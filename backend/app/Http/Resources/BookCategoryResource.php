<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BookCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BookCategory
 */
class BookCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'academic_program_id' => $this->academic_program_id,
            'academic_program' => $this->whenLoaded('academicProgram', fn () => $this->academicProgram !== null ? [
                'id' => $this->academicProgram->id,
                'code' => $this->academicProgram->code,
                'name' => $this->academicProgram->name,
            ] : null),
            'is_active' => $this->is_active,
            'books_count' => $this->whenCounted('books'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
