<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CoursePackage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CoursePackage
 */
class CoursePackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'academic_program_id' => $this->academic_program_id,
            'academic_program' => $this->whenLoaded('academicProgram', fn () => $this->academicProgram !== null ? ['id' => $this->academicProgram->id, 'code' => $this->academicProgram->code, 'name' => $this->academicProgram->name] : null),
            'description' => $this->description,
            'price' => (float) $this->price,
            'duration' => $this->duration,
            'product_id' => $this->product_id,
            'is_active' => $this->is_active,
            'books' => $this->whenLoaded('books', fn () => $this->books->map(fn ($book) => [
                'id' => $book->id,
                'title' => $book->title,
                'fee' => $book->fee !== null ? (float) $book->fee : null,
                'sort_order' => $book->pivot->sort_order,
                'is_required' => $book->pivot->is_required,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
