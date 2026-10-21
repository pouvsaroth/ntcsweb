<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ClassSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClassSchedule
 */
class ClassScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'day_of_week' => $this->day_of_week,
            'day_name' => $this->dayName(),
            'start_time' => substr((string) $this->start_time, 0, 5),
            'end_time' => substr((string) $this->end_time, 0, 5),
            // Timetable details (docs/academics/timetable.md); empty = the class's own.
            'book_id' => $this->book_id,
            'staff_id' => $this->staff_id,
            'classroom_id' => $this->classroom_id,
        ];
    }
}
