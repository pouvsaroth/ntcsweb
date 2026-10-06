<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Staff
 */
class StaffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_code' => $this->employee_code,

            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),
            'other_name' => $this->other_name,

            'gender' => $this->gender,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'birth_place' => $this->birth_place,
            'national_id' => $this->national_id,
            'national_id_photo_url' => $this->nationalIdPhotoUrl(),

            'email' => $this->email,
            'phone' => $this->phone,

            'house_no' => $this->house_no,
            'street_no' => $this->street_no,
            'village_code' => $this->village_code,

            'facebook' => $this->facebook,
            'telegram' => $this->telegram,
            'other_contact' => $this->other_contact,

            'photo_url' => $this->photoUrl(),
            'signature_url' => $this->signatureUrl(),
            'profile_color' => $this->profile_color,

            'hire_date' => $this->hire_date?->toDateString(),
            'status' => $this->status,
            'position' => new PositionResource($this->whenLoaded('position')),

            // HRM > Organization Management placement — ids for the form,
            // names for display.
            'branch_id' => $this->branch_id,
            'department_id' => $this->department_id,
            'team_id' => $this->team_id,
            'job_grade_id' => $this->job_grade_id,
            'job_level_id' => $this->job_level_id,
            'reports_to_staff_id' => $this->reports_to_staff_id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch ? ['id' => $this->branch->id, 'name' => $this->branch->name] : null),
            'department' => $this->whenLoaded('department', fn () => $this->department ? ['id' => $this->department->id, 'name' => $this->department->name] : null),
            'team' => $this->whenLoaded('team', fn () => $this->team ? ['id' => $this->team->id, 'name' => $this->team->name] : null),
            'job_grade' => $this->whenLoaded('jobGrade', fn () => $this->jobGrade ? ['id' => $this->jobGrade->id, 'name' => $this->jobGrade->name] : null),
            'job_level' => $this->whenLoaded('jobLevel', fn () => $this->jobLevel ? ['id' => $this->jobLevel->id, 'name' => $this->jobLevel->name] : null),
            'reports_to' => $this->whenLoaded('reportsTo', fn () => $this->reportsTo ? [
                'id' => $this->reportsTo->id,
                'full_name' => $this->reportsTo->fullName(),
                'employee_code' => $this->reportsTo->employee_code,
            ] : null),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'email' => $this->user->email,
                'phone' => $this->user->phone,
                'status' => $this->user->status,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
