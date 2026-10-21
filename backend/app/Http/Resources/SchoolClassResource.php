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

            'teachers' => $this->whenLoaded('teachers', fn () => $this->teachers->map(fn ($staff) => ['id' => $staff->id, 'name' => $staff->fullName()])->values()),
            'assistant_teachers' => $this->whenLoaded('assistantTeachers', fn () => $this->assistantTeachers->map(fn ($staff) => ['id' => $staff->id, 'name' => $staff->fullName()])->values()),
            'classroom' => new ClassroomResource($this->whenLoaded('classroom')),
            'schedules' => ClassScheduleResource::collection($this->whenLoaded('schedules')),
            'academic_program_id' => $this->academic_program_id,
            'academic_program' => new AcademicProgramResource($this->whenLoaded('academicProgram')),
            'grade_level_id' => $this->grade_level_id,
            'grade_level' => $this->whenLoaded('gradeLevel', fn () => $this->gradeLevel ? ['id' => $this->gradeLevel->id, 'name' => $this->gradeLevel->name, 'code' => $this->gradeLevel->code] : null),
            'academic_year_id' => $this->academic_year_id,
            'academic_year' => $this->whenLoaded('academicYear', fn () => $this->academicYear ? ['id' => $this->academicYear->id, 'name' => $this->academicYear->name] : null),
            'active_students_count' => $this->whenCounted('active_students'),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
