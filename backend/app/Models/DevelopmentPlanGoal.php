<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. A goal on a development plan: what, how, the support
 * (optionally a training or coaching), a target date and progress.
 * See docs/hrm/professional-development.md.
 */
#[Fillable(['development_plan_id', 'title', 'area', 'actions', 'support', 'training_id', 'coaching_id', 'target_date', 'status', 'progress', 'staff_note', 'sort_order'])]
class DevelopmentPlanGoal extends Model
{
    use Auditable;

    public const STATUSES = ['not_started', 'in_progress', 'done', 'dropped'];

    protected $connection = 'tenant';

    protected $attributes = [
        'status' => 'not_started',
        'progress' => 0,
    ];

    protected function casts(): array
    {
        return [
            'target_date' => 'date',
            'progress' => 'integer',
        ];
    }

    /** @return BelongsTo<DevelopmentPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(DevelopmentPlan::class, 'development_plan_id');
    }

    /** @return BelongsTo<Training, $this> */
    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }

    /** @return BelongsTo<Coaching, $this> */
    public function coaching(): BelongsTo
    {
        return $this->belongsTo(Coaching::class);
    }

    public function auditModule(): string
    {
        return 'HRM';
    }

    public function auditDisplayName(): string
    {
        return (string) $this->title;
    }
}
