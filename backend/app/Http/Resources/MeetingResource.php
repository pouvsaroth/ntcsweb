<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Meeting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Meeting
 */
class MeetingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'meeting_date' => $this->meeting_date?->toDateString(),
            'start_time' => $this->start_time ? substr((string) $this->start_time, 0, 5) : null,
            'end_time' => $this->end_time ? substr((string) $this->end_time, 0, 5) : null,
            'location' => $this->location,
            'agenda' => $this->agenda,
            'minutes' => $this->minutes,
            'status' => $this->status,
            'organizer_staff_id' => $this->organizer_staff_id,
            'organizer' => $this->whenLoaded('organizer', fn () => $this->organizer ? ['id' => $this->organizer->id, 'name' => $this->organizer->fullName()] : null),
            'guests' => $this->guests,
            'participants_count' => $this->whenCounted('participants'),
            'participants' => $this->whenLoaded('participants', fn () => $this->participants->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->fullName(),
                'employee_code' => $s->employee_code,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
