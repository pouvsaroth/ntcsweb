<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LeaveType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LeaveType
 */
class LeaveTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'color' => $this->color,
            'is_paid' => $this->is_paid,
            'allow_half_day' => $this->allow_half_day,
            'requires_attachment' => $this->requires_attachment,
            'gender' => $this->gender,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'policies_count' => $this->whenCounted('policies'),
        ];
    }
}
