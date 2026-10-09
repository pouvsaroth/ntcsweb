<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Shift
 */
class ShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'start_time' => substr((string) $this->start_time, 0, 5),
            'end_time' => substr((string) $this->end_time, 0, 5),
            'break_minutes' => $this->break_minutes,
            'late_grace_minutes' => $this->late_grace_minutes,
            'early_leave_grace_minutes' => $this->early_leave_grace_minutes,
            'work_minutes' => $this->workMinutes(),
            'overnight' => $this->isOvernight(),
            'color' => $this->color,
            'is_active' => $this->is_active,
            // Day / From / To rows (ISO 1 = Monday … 7 = Sunday); empty for a shift from before shift_days — see Shift::timesOn().
            'days' => $this->whenLoaded('days', fn () => $this->days->map(fn ($day) => [
                'day_of_week' => $day->day_of_week,
                'start_time' => substr((string) $day->start_time, 0, 5),
                'end_time' => substr((string) $day->end_time, 0, 5),
                // A row from before per-day breaks falls back to the shift's own — see Shift::breakOn().
                'break_minutes' => $day->break_minutes ?? $this->break_minutes,
            ])->values()),
        ];
    }
}
