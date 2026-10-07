<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\PerformanceCycleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned. A review period (HRM > Performance Management > Performance
 * review) — "2026 annual", "2026 H1" — with the evaluation form its reviews
 * use and when the self / manager assessments are due. Draft while being
 * set up, active while reviews are done, closed when finished.
 */
#[Fillable(['name', 'start_date', 'end_date', 'self_assessment_due', 'manager_assessment_due', 'evaluation_form_id', 'status', 'description'])]
class PerformanceCycle extends Model
{
    /** @use HasFactory<PerformanceCycleFactory> */
    use Auditable, HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_CLOSED];

    protected $connection = 'tenant';

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'self_assessment_due' => 'date',
            'manager_assessment_due' => 'date',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(EvaluationForm::class, 'evaluation_form_id');
    }

    public function goals(): HasMany
    {
        return $this->hasMany(PerformanceGoal::class);
    }

    public function auditModule(): string
    {
        return 'Performance';
    }
}
