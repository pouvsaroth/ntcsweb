<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Branch;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Branch/Team/JobGrade/JobLevel — see OrganizationUnitController. Only a
 * branch carries phone/address, and only a team its department.
 */
class OrganizationUnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            ...$this->resource instanceof Branch ? ['phone' => $this->phone, 'address' => $this->address] : [],
            ...$this->resource instanceof Team ? [
                'department_id' => $this->department_id,
                'department' => $this->whenLoaded('department', fn () => $this->department ? ['id' => $this->department->id, 'name' => $this->department->name] : null),
            ] : [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
