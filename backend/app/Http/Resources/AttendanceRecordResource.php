<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AttendanceRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AttendanceRecord
 */
class AttendanceRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'enrollment_id' => $this->enrollment_id,
            'date' => $this->date?->toDateString(),
            'status' => $this->status,
            'late_minutes' => $this->late_minutes,
            'remarks' => $this->remarks,
            'student' => $this->whenLoaded('student', fn () => [
                'id' => $this->student->id,
                'student_code' => $this->student->student_code,
                'name' => $this->student->fullName(),
            ]),
            'class' => $this->whenLoaded('schoolClass', function () {
                // The weekly slot this attendance date actually fell on — a class can meet
                // several days a week, each with its own time, so we match by day-of-week
                // rather than just taking the first schedule row.
                $schedule = $this->schoolClass->relationLoaded('schedules')
                    ? $this->schoolClass->schedules->firstWhere('day_of_week', $this->date?->dayOfWeekIso)
                    : null;

                return [
                    'id' => $this->schoolClass->id,
                    'name' => $this->schoolClass->name,
                    'start_time' => $schedule ? substr((string) $schedule->start_time, 0, 5) : null,
                    'end_time' => $schedule ? substr((string) $schedule->end_time, 0, 5) : null,
                ];
            }),
            'recorded_by' => $this->whenLoaded('recordedBy', fn () => $this->recordedBy?->name),
            'recorded_at' => $this->recorded_at?->toIso8601String(),
        ];
    }
}
