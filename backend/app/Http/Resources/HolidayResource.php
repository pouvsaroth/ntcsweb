<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Holiday;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Holiday
 */
class HolidayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'holiday_type' => $this->holiday_type,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'days' => $this->start_date && $this->end_date ? $this->start_date->diffInDays($this->end_date) + 1 : 1,
            'description' => $this->description,
            'cancelled_permissions' => $this->when($this->resource->cancelledPermissions !== null, fn () => $this->resource->cancelledPermissions),
        ];
    }
}
