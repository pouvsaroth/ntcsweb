<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SchoolClass;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SchoolClass
 */
class SchoolClassResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'capacity' => $this->capacity,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'status' => $this->status,

            'teacher' => $this->whenLoaded('teacher', fn () => $this->teacher !== null ? ['id' => $this->teacher->id, 'name' => $this->teacher->fullName()] : null),
            'classroom' => new ClassroomResource($this->whenLoaded('classroom')),
            'schedules' => ClassScheduleResource::collection($this->whenLoaded('schedules')),
            'books' => BookResource::collection($this->whenLoaded('books')),
            'academic_program_id' => $this->academic_program_id,
            'academic_program' => new AcademicProgramResource($this->whenLoaded('academicProgram')),
            'course_packages' => CoursePackageResource::collection($this->whenLoaded('coursePackages')),
            'enrollments_count' => $this->whenCounted('enrollments'),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
