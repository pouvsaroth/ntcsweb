<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CoursePackage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public-facing shape of a course package — only what a visitor should
 * see. Deliberately omits `code`/`product_id`/`is_active`/`show_on_website`
 * and the book menu; internal catalog/billing plumbing, not marketing copy.
 *
 * @mixin CoursePackage
 */
class PublicCoursePackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'thumbnail_url' => $this->thumbnailUrl(),
            'fee_monthly' => $this->fee_monthly !== null ? (float) $this->fee_monthly : null,
            'fee_term' => $this->fee_term !== null ? (float) $this->fee_term : null,
            'fee_video' => $this->fee_video !== null ? (float) $this->fee_video : null,
            'fee_monthly_online' => $this->fee_monthly_online !== null ? (float) $this->fee_monthly_online : null,
            'fee_term_online' => $this->fee_term_online !== null ? (float) $this->fee_term_online : null,
            'currency' => $this->currency,
            'duration' => $this->duration,
            'academic_program' => $this->whenLoaded('academicProgram', fn () => $this->academicProgram !== null ? [
                'id' => $this->academicProgram->id,
                'name' => $this->academicProgram->name,
                'sort_order' => $this->academicProgram->sort_order,
            ] : null),
        ];
    }
}
