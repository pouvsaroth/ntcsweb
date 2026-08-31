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
            'profile_color' => $this->profile_color,

            'hire_date' => $this->hire_date?->toDateString(),
            'status' => $this->status,
            'position' => new PositionResource($this->whenLoaded('position')),
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
