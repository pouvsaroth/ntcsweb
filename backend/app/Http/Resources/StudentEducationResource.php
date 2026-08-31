<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\StudentEducation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StudentEducation
 */
class StudentEducationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'school_name' => $this->school_name,
            'address' => $this->address,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'skill' => $this->skill,
            'detail' => $this->detail,
        ];
    }
}
