<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Shift
 */
class ShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'start_time' => substr((string) $this->start_time, 0, 5),
            'end_time' => substr((string) $this->end_time, 0, 5),
            'break_minutes' => $this->break_minutes,
            'late_grace_minutes' => $this->late_grace_minutes,
            'early_leave_grace_minutes' => $this->early_leave_grace_minutes,
            'work_minutes' => $this->workMinutes(),
            'overnight' => $this->isOvernight(),
            'color' => $this->color,
            'is_active' => $this->is_active,
        ];
    }
}
