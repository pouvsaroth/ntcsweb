<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Enrollment
 */
class EnrollmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'enrolled_at' => $this->enrolled_at?->toDateString(),
            'fee' => (float) $this->fee,
            'fee_type' => $this->fee_type,
            'status' => $this->status,
            'student' => new StudentResource($this->whenLoaded('student')),
            'class' => new SchoolClassResource($this->whenLoaded('schoolClass')),
            'table_id' => $this->table_id,
            'table' => new ClassroomTableResource($this->whenLoaded('table')),
            'book' => new BookResource($this->whenLoaded('book')),
            'course_package_id' => $this->course_package_id,
            'course_package' => new CoursePackageResource($this->whenLoaded('coursePackage')),
            'academic_program_id' => $this->academic_program_id,
            'academic_program' => new AcademicProgramResource($this->whenLoaded('academicProgram')),
            'study_mode_id' => $this->study_mode_id,
            'study_mode' => new StudyModeResource($this->whenLoaded('studyMode')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
