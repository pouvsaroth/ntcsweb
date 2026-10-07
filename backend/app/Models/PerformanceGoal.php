<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\PerformanceGoalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. One of a staff member's goals (HRM > Performance Management >
 * Goals) for a review cycle, or open-ended — with progress, and the self /
 * manager rating (1–5) given during the review. `weight` is its share of the
 * goals part of the score (all 0 = equal shares).
 */
#[Fillable(['staff_id', 'performance_cycle_id', 'title', 'description', 'due_date', 'weight', 'progress', 'status', 'self_rating', 'manager_rating', 'created_by'])]
class PerformanceGoal extends Model
{
    /** @use HasFactory<PerformanceGoalFactory> */
    use Auditable, HasFactory;

    public const STATUS_NOT_STARTED = 'not_started';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_NOT_STARTED, self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED, self::STATUS_CANCELLED];

    protected $connection = 'tenant';

    protected $attributes = [
        'weight' => 0,
        'progress' => 0,
        'status' => self::STATUS_NOT_STARTED,
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'weight' => 'integer',
            'progress' => 'integer',
            'self_rating' => 'integer',
            'manager_rating' => 'integer',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceCycle::class, 'performance_cycle_id');
    }

    public function auditModule(): string
    {
        return 'Performance';
    }
}
