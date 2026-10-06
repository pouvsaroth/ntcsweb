<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\WorkSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkSchedule
 */
class WorkScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'monday_shift_id' => $this->monday_shift_id,
            'tuesday_shift_id' => $this->tuesday_shift_id,
            'wednesday_shift_id' => $this->wednesday_shift_id,
            'thursday_shift_id' => $this->thursday_shift_id,
            'friday_shift_id' => $this->friday_shift_id,
            'saturday_shift_id' => $this->saturday_shift_id,
            'sunday_shift_id' => $this->sunday_shift_id,
            'is_default' => $this->is_default,
            'staff_count' => $this->whenCounted('staff'),
            'staff' => $this->whenLoaded('staff', fn () => $this->staff->map(fn ($s) => ['id' => $s->id, 'name' => $s->fullName(), 'employee_code' => $s->employee_code])->values()),
        ];
    }
}
