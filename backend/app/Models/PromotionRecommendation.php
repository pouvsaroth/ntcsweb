<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Audit\AuditAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. A recommendation to promote a staff member (HRM >
 * Performance Management > Promotion recommendation) — new position / job
 * grade / job level and an optional new basic salary from a date. Pending →
 * approved → applied (see PromotionService); or rejected / cancelled.
 */
#[Fillable([
    'staff_id', 'performance_review_id',
    'from_position_id', 'to_position_id', 'from_job_grade_id', 'to_job_grade_id', 'from_job_level_id', 'to_job_level_id',
    'from_basic_salary', 'new_basic_salary', 'salary_currency', 'effective_date', 'reason', 'status',
    'requested_by', 'decided_by', 'decided_at', 'decision_reason', 'applied_at',
])]
class PromotionRecommendation extends Model
{
    use Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    protected $connection = 'tenant';

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'from_basic_salary' => 'float',
            'new_basic_salary' => 'float',
            'effective_date' => 'date',
            'decided_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(PerformanceReview::class, 'performance_review_id');
    }

    public function fromPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'from_position_id');
    }

    public function toPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'to_position_id');
    }

    public function fromJobGrade(): BelongsTo
    {
        return $this->belongsTo(JobGrade::class, 'from_job_grade_id');
    }

    public function toJobGrade(): BelongsTo
    {
        return $this->belongsTo(JobGrade::class, 'to_job_grade_id');
    }

    public function fromJobLevel(): BelongsTo
    {
        return $this->belongsTo(JobLevel::class, 'from_job_level_id');
    }

    public function toJobLevel(): BelongsTo
    {
        return $this->belongsTo(JobLevel::class, 'to_job_level_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function auditModule(): string
    {
        return 'Performance';
    }

    protected function auditActionForDirty(array $dirty): string
    {
        return array_key_exists('status', $dirty) ? AuditAction::STATUS_CHANGE : AuditAction::UPDATE;
    }
}
