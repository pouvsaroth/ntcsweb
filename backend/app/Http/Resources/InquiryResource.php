<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Inquiry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Inquiry
 */
class InquiryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'inquiry_date' => $this->inquiry_date?->toDateString(),
            'name' => $this->name,
            'gender' => $this->gender,
            'phone' => $this->phone,
            'email' => $this->email,
            'interested_in' => $this->interested_in,
            'source' => $this->source,
            'status' => $this->status,
            'next_follow_up_on' => $this->next_follow_up_on?->toDateString(),
            'assigned_staff_id' => $this->assigned_staff_id,
            'assigned_staff' => $this->whenLoaded('assignedStaff', fn () => $this->assignedStaff ? ['id' => $this->assignedStaff->id, 'name' => $this->assignedStaff->fullName()] : null),
            'student_id' => $this->student_id,
            'student' => $this->whenLoaded('student', fn () => $this->student ? ['id' => $this->student->id, 'name' => $this->student->fullName(), 'student_code' => $this->student->student_code ?? null] : null),
            'note' => $this->note,
            'follow_ups_count' => $this->whenCounted('followUps'),
            'follow_ups' => $this->whenLoaded('followUps', fn () => $this->followUps->map(fn ($f) => [
                'id' => $f->id,
                'followed_up_on' => $f->followed_up_on?->toDateString(),
                'method' => $f->method,
                'note' => $f->note,
                'next_follow_up_on' => $f->next_follow_up_on?->toDateString(),
                'created_by_name' => $f->created_by_name,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
