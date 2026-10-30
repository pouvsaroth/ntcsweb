<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned. One staff member's development plan for a period: goals,
 * the support they get, and progress, followed up by a reviewer.
 * See docs/hrm/professional-development.md.
 */
#[Fillable(['staff_id', 'reviewer_staff_id', 'title', 'start_date', 'end_date', 'status', 'summary', 'review_note', 'completed_at', 'created_by'])]
class DevelopmentPlan extends Model
{
    use Auditable;

    public const STATUSES = ['draft', 'active', 'completed', 'cancelled'];

    protected $connection = 'tenant';

    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Staff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id')->withTrashed();
    }

    /** @return BelongsTo<Staff, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'reviewer_staff_id')->withTrashed();
    }

    /** @return HasMany<DevelopmentPlanGoal, $this> */
    public function goals(): HasMany
    {
        return $this->hasMany(DevelopmentPlanGoal::class);
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
