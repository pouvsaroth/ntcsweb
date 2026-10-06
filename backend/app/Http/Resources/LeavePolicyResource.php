<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LeavePolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LeavePolicy
 */
class LeavePolicyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'leave_type_id' => $this->leave_type_id,
            'leave_type' => $this->whenLoaded('leaveType', fn () => [
                'id' => $this->leaveType->id,
                'code' => $this->leaveType->code,
                'name' => $this->leaveType->name,
                'color' => $this->leaveType->color,
            ]),
            'job_grade_id' => $this->job_grade_id,
            'job_grade' => $this->whenLoaded('jobGrade', fn () => $this->jobGrade
                ? ['id' => $this->jobGrade->id, 'code' => $this->jobGrade->code, 'name' => $this->jobGrade->name]
                : null),
            'name' => $this->name,
            'days_per_year' => $this->days_per_year,
            'min_service_months' => $this->min_service_months,
            'prorate_first_year' => $this->prorate_first_year,
            'service_bonus_every_years' => $this->service_bonus_every_years,
            'service_bonus_days' => $this->service_bonus_days,
            'max_days_per_year' => $this->max_days_per_year,
            'max_carry_forward_days' => $this->max_carry_forward_days,
            'carry_forward_expiry_months' => $this->carry_forward_expiry_months,
            'max_consecutive_days' => $this->max_consecutive_days,
            'min_notice_days' => $this->min_notice_days,
            'description' => $this->description,
            'is_active' => $this->is_active,
        ];
    }
}
