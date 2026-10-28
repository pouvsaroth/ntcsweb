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
            'enrollments_code' => $this->enrollments_code,
            'enrolled_at' => $this->enrolled_at?->toDateString(),
            'status' => $this->status,
            'note' => $this->note,
            // Who looks after this student today (Class > Students > Responsible Staff).
            'responsible_staff' => $this->whenLoaded('currentResponsibleStaff', fn () => $this->currentResponsibleStaff === null ? null : [
                'id' => $this->currentResponsibleStaff->id,
                'staff_id' => $this->currentResponsibleStaff->staff_id,
                'name' => $this->currentResponsibleStaff->staff?->fullName() ?? '',
                'from_date' => $this->currentResponsibleStaff->from_date?->toDateString(),
                'to_date' => $this->currentResponsibleStaff->to_date?->toDateString(),
            ]),
            // When the current status started: its effective date, else the
            // day it was changed. Null when it never changed (still the
            // status it was enrolled with) — the enroll date covers that.
            'status_date' => $this->whenLoaded('latestStatusChange', fn () => $this->latestStatusChange !== null && $this->latestStatusChange->to_status === $this->status
                ? ($this->latestStatusChange->effective_date ?? $this->latestStatusChange->created_at)?->toDateString()
                : null),
            'is_paid' => $this->isPaid(),
            'student' => new StudentResource($this->whenLoaded('student')),
            'class' => new SchoolClassResource($this->whenLoaded('schoolClass')),
            'table_id' => $this->table_id,
            'table' => new ClassroomTableResource($this->whenLoaded('table')),
            'course_package_id' => $this->course_package_id,
            'course_package' => new CoursePackageResource($this->whenLoaded('coursePackage')),
            'academic_program_id' => $this->academic_program_id,
            'academic_program' => new AcademicProgramResource($this->whenLoaded('academicProgram')),
            'created_at' => $this->created_at?->toIso8601String(),
            // Only set right after EnrollmentService::enrollInPackage() creates
            // the invoice alongside it — absent everywhere else this resource
            // is used (index/show/transfer/etc.).
            'invoice_id' => $this->when(array_key_exists('invoice_id', $this->getAttributes()), fn () => $this->getAttribute('invoice_id')),
            'invoice_number' => $this->when(array_key_exists('invoice_number', $this->getAttributes()), fn () => $this->getAttribute('invoice_number')),
        ];
    }
}
