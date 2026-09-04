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
            'thumbnail_url' => $this->thumbnailUrl(),
            'price' => (float) $this->price,
            'fee_monthly' => $this->fee_monthly !== null ? (float) $this->fee_monthly : null,
            'fee_term' => $this->fee_term !== null ? (float) $this->fee_term : null,
            'fee_video' => $this->fee_video !== null ? (float) $this->fee_video : null,
            'fee_monthly_online' => $this->fee_monthly_online !== null ? (float) $this->fee_monthly_online : null,
            'fee_term_online' => $this->fee_term_online !== null ? (float) $this->fee_term_online : null,
            'currency' => $this->currency,
            'duration' => $this->duration,
            'product_id' => $this->product_id,
            'is_active' => $this->is_active,
            'show_on_website' => $this->show_on_website,
            'show_in_popular' => $this->show_in_popular,
            'show_videos' => $this->show_videos,
            'books' => $this->whenLoaded('books', fn () => $this->books->map(fn ($book) => [
                'id' => $book->id,
                'title' => $book->title,
                'sort_order' => $book->pivot->sort_order,
                'is_required' => $book->pivot->is_required,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
