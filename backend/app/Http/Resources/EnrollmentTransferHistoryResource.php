<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EnrollmentTransferHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EnrollmentTransferHistory
 */
class EnrollmentTransferHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_class' => $this->fromClass?->name,
            'to_class' => $this->toClass?->name,
            'from_table' => $this->fromTable?->name,
            'to_table' => $this->toTable?->name,
            'from_course_package' => $this->fromCoursePackage?->name,
            'to_course_package' => $this->toCoursePackage?->name,
            'changed_by' => $this->whenLoaded('changedBy', fn () => $this->changedBy?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
