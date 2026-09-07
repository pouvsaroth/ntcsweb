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

            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),
            'english_name' => $this->english_name,

            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'gender' => $this->gender,
            'email' => $this->email,
            'phone' => $this->phone,

            'house_no' => $this->house_no,
            'street_no' => $this->street_no,
            'village_code' => $this->village_code,
            'other_address' => $this->other_address,
            // Village -> Commune -> District -> Province, coarsest last —
            // only meaningful once `village_code` actually resolves against
            // the `villages` reference table (see Student::village()); a
            // code with no match, or none at all, yields null rather than a
            // fragment.
            'address' => $this->whenLoaded('village', fn () => $this->formatAddress()),

            'facebook' => $this->facebook,
            'telegram' => $this->telegram,
            'photo_url' => $this->photoUrl(),

            'enrollment_date' => $this->enrollment_date?->toDateString(),
            'status' => $this->status,
            'user_id' => $this->user_id,
            'enrollments_count' => $this->whenCounted('enrollments'),
            'guardians_count' => $this->whenCounted('guardians'),
            'educations_count' => $this->whenCounted('educations'),
            'guardians' => StudentGuardianResource::collection($this->whenLoaded('guardians')),
            'educations' => StudentEducationResource::collection($this->whenLoaded('educations')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function formatAddress(): ?string
    {
        $village = $this->village;

        if ($village === null) {
            return null;
        }

        $parts = [
            $village->name_km,
            $village->commune?->name_km,
            $village->commune?->district?->name_km,
            $village->commune?->district?->province?->name_km,
        ];

        $parts = array_filter($parts, fn (?string $part) => $part !== null && $part !== '');

        return $parts === [] ? null : implode(', ', $parts);
    }
}
