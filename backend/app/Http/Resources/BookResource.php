<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Book;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Book
 */
class BookResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'author' => $this->author,
            'isbn' => $this->isbn,
            'publisher' => $this->publisher,
            'description' => $this->description,
            'cover_image' => $this->cover_image,
            'status' => $this->status,
            'academic_program_id' => $this->academic_program_id,
            'academic_program' => $this->whenLoaded('academicProgram', fn () => $this->academicProgram !== null ? [
                'id' => $this->academicProgram->id,
                'code' => $this->academicProgram->code,
                'name' => $this->academicProgram->name,
            ] : null),
            'book_category_id' => $this->book_category_id,
            'book_category' => $this->whenLoaded('bookCategory', fn () => $this->bookCategory !== null ? [
                'id' => $this->bookCategory->id,
                'name' => $this->bookCategory->name,
            ] : null),
            'classes_count' => $this->whenCounted('classes'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
