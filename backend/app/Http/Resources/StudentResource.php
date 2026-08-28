<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Student
 */
class StudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_code' => $this->student_code,
            'name' => $this->name,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'gender' => $this->gender,
            'email' => $this->email,
            'phone' => $this->phone,
            'guardian_name' => $this->guardian_name,
            'guardian_phone' => $this->guardian_phone,
            'address' => $this->address,
            'enrollment_date' => $this->enrollment_date?->toDateString(),
            'status' => $this->status,
            'user_id' => $this->user_id,
            'enrollments_count' => $this->whenCounted('enrollments'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
