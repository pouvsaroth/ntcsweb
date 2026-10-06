<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\LeavePolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. How many days of a LeaveType a staff member gets each year
 * (HRM > Leave Management > Leave policies) — for everyone (`job_grade_id`
 * null) or one job grade; a grade's own policy wins over the everyone one
 * (see forStaff()).
 *
 * - `min_service_months`: none until they've worked this long.
 * - `prorate_first_year`: in the year they join, only the share of the year
 *   from their hire date (to the nearest half day).
 * - `service_bonus_every_years` / `service_bonus_days`: e.g. +1 day every 3
 *   years of service, never past `max_days_per_year` when set.
 * - `max_carry_forward_days`: unused days that move into the next year
 *   (0 = none), used up by `carry_forward_expiry_months` into it if set.
 * - `max_consecutive_days` / `min_notice_days`: limits on one request.
 */
#[Fillable([
    'leave_type_id', 'job_grade_id', 'name', 'days_per_year', 'min_service_months', 'prorate_first_year',
    'service_bonus_every_years', 'service_bonus_days', 'max_days_per_year', 'max_carry_forward_days',
    'carry_forward_expiry_months', 'max_consecutive_days', 'min_notice_days', 'description', 'is_active',
])]
class LeavePolicy extends Model
{
    /** @use HasFactory<LeavePolicyFactory> */
    use Auditable, HasFactory;

    protected $connection = 'tenant';

    protected $attributes = [
        'min_service_months' => 0,
        'prorate_first_year' => true,
        'service_bonus_days' => 0,
        'max_carry_forward_days' => 0,
        'min_notice_days' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'days_per_year' => 'float',
            'min_service_months' => 'integer',
            'prorate_first_year' => 'boolean',
            'service_bonus_every_years' => 'integer',
            'service_bonus_days' => 'float',
            'max_days_per_year' => 'float',
            'max_carry_forward_days' => 'float',
            'carry_forward_expiry_months' => 'integer',
            'max_consecutive_days' => 'integer',
            'min_notice_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function jobGrade(): BelongsTo
    {
        return $this->belongsTo(JobGrade::class);
    }

    /** The active policy of this type that applies to a staff member — their grade's own, else the everyone one. */
    public static function forStaff(Staff $staff, int $leaveTypeId): ?self
    {
        return self::query()
            ->where('leave_type_id', $leaveTypeId)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('job_grade_id')->orWhere('job_grade_id', $staff->job_grade_id))
            ->orderByRaw('job_grade_id is null')
            ->first();
    }

    public function auditModule(): string
    {
        return 'Leave';
    }
}
