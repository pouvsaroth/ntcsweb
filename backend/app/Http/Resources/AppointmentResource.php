<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Appointment
 */
class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'visitor_name' => $this->visitor_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'organization' => $this->organization,
            'purpose' => $this->purpose,
            'appointment_date' => $this->appointment_date?->toDateString(),
            'start_time' => $this->start_time ? substr((string) $this->start_time, 0, 5) : null,
            'end_time' => $this->end_time ? substr((string) $this->end_time, 0, 5) : null,
            'host_staff_id' => $this->host_staff_id,
            'host' => $this->whenLoaded('host', fn () => $this->host ? ['id' => $this->host->id, 'name' => $this->host->fullName()] : null),
            'status' => $this->status,
            'note' => $this->note,
            'visitor' => $this->whenLoaded('visitor', fn () => $this->visitor ? [
                'id' => $this->visitor->id,
                'check_in_at' => $this->visitor->check_in_at?->toIso8601String(),
                'check_out_at' => $this->visitor->check_out_at?->toIso8601String(),
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
