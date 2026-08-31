<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One roster row = one enrolled student, paired with their attendance record
 * for the requested date if one was already taken. `status`/`remarks` are
 * null (not defaulted to PRESENT) when nothing has been recorded yet, so the
 * frontend can tell "not marked" apart from "marked present".
 *
 * @mixin Enrollment
 */
class AttendanceRosterEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var \App\Models\AttendanceRecord|null $record */
        $record = $this->attendanceRecords->first();

        return [
            'enrollment_id' => $this->id,
            'student' => [
                'id' => $this->student->id,
                'student_code' => $this->student->student_code,
                'name' => $this->student->fullName(),
            ],
            'attendance_record_id' => $record?->id,
            'status' => $record?->status,
            'remarks' => $record?->remarks,
        ];
    }
}
