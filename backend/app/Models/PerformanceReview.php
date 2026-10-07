<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Audit\AuditAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned. One staff member's review in a cycle (HRM > Performance
 * Management > Performance review), assessed by `reviewer` (their Reports-to
 * when launched). Self assessment → manager assessment → completed; scores
 * are 1–5 (see PerformanceScorer). The KPIs and form questions are the
 * review's own copies.
 */
#[Fillable([
    'performance_cycle_id', 'staff_id', 'reviewer_staff_id', 'status',
    'self_comment', 'self_submitted_at', 'manager_comment', 'manager_overall_rating', 'manager_submitted_at',
    'kpi_score', 'goal_score', 'manager_score', 'self_score', 'final_score', 'created_by',
])]
class PerformanceReview extends Model
{
    use Auditable;

    public const STATUS_SELF = 'self_assessment';

    public const STATUS_MANAGER = 'manager_assessment';

    public const STATUS_COMPLETED = 'completed';

    protected $connection = 'tenant';

    protected $attributes = [
        'status' => self::STATUS_SELF,
    ];

    protected function casts(): array
    {
        return [
            'self_submitted_at' => 'datetime',
            'manager_submitted_at' => 'datetime',
            'manager_overall_rating' => 'integer',
            'kpi_score' => 'float',
            'goal_score' => 'float',
            'manager_score' => 'float',
            'self_score' => 'float',
            'final_score' => 'float',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceCycle::class, 'performance_cycle_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'reviewer_staff_id');
    }

    public function kpis(): HasMany
    {
        return $this->hasMany(PerformanceReviewKpi::class)->orderBy('sort_order')->orderBy('id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(PerformanceReviewAnswer::class)->orderBy('sort_order')->orderBy('id');
    }

    /** The staff member's goals for this review's cycle (cancelled ones aside). */
    public function goals(): HasMany
    {
        return $this->hasMany(PerformanceGoal::class, 'staff_id', 'staff_id')
            ->where('performance_cycle_id', $this->performance_cycle_id)
            ->where('status', '!=', PerformanceGoal::STATUS_CANCELLED)
            ->orderBy('id');
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
