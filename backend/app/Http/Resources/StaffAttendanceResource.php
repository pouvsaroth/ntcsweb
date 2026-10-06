<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\StaffAttendance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StaffAttendance
 */
class StaffAttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'staff_id' => $this->staff_id,
            'staff' => $this->whenLoaded('staff', fn () => $this->staff !== null ? [
                'id' => $this->staff->id,
                'name' => $this->staff->fullName(),
                'employee_code' => $this->staff->employee_code,
            ] : null),
            'date' => $this->date?->toDateString(),
            'shift' => $this->whenLoaded('shift', fn () => $this->shift !== null ? [
                'id' => $this->shift->id,
                'name' => $this->shift->name,
                'start_time' => substr((string) $this->shift->start_time, 0, 5),
                'end_time' => substr((string) $this->shift->end_time, 0, 5),
                'color' => $this->shift->color,
            ] : null),
            'scheduled_start' => $this->scheduled_start?->toIso8601String(),
            'scheduled_end' => $this->scheduled_end?->toIso8601String(),
            'check_in_at' => $this->check_in_at?->toIso8601String(),
            'check_out_at' => $this->check_out_at?->toIso8601String(),
            'check_in_source' => $this->check_in_source,
            'check_out_source' => $this->check_out_source,
            'check_in_location' => $this->check_in_latitude !== null ? ['lat' => (float) $this->check_in_latitude, 'lng' => (float) $this->check_in_longitude] : null,
            'check_out_location' => $this->check_out_latitude !== null ? ['lat' => (float) $this->check_out_latitude, 'lng' => (float) $this->check_out_longitude] : null,
            'status' => $this->status,
            'late_minutes' => $this->late_minutes,
            'early_leave_minutes' => $this->early_leave_minutes,
            'worked_minutes' => $this->worked_minutes,
            'overtime_minutes' => $this->overtime_minutes,
            'note' => $this->note,
        ];
    }
}
