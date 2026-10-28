<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Visitor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Visitor
 */
class VisitorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'appointment_id' => $this->appointment_id,
            'name' => $this->name,
            'phone' => $this->phone,
            'id_number' => $this->id_number,
            'organization' => $this->organization,
            'purpose' => $this->purpose,
            'host_staff_id' => $this->host_staff_id,
            'host' => $this->whenLoaded('host', fn () => $this->host ? ['id' => $this->host->id, 'name' => $this->host->fullName()] : null),
            'people_count' => $this->people_count,
            'badge_no' => $this->badge_no,
            'check_in_at' => $this->check_in_at?->toIso8601String(),
            'check_out_at' => $this->check_out_at?->toIso8601String(),
            'note' => $this->note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
