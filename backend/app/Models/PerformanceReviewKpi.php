<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. A review's own copy of one KPI — its target and weight for
 * this review, the actual result, and the self / manager rating (1–5).
 */
#[Fillable(['performance_review_id', 'kpi_id', 'name', 'measurement', 'unit', 'target', 'higher_is_better', 'weight', 'actual', 'self_rating', 'manager_rating', 'comment', 'sort_order'])]
class PerformanceReviewKpi extends Model
{
    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'target' => 'float',
            'actual' => 'float',
            'higher_is_better' => 'boolean',
            'weight' => 'integer',
            'self_rating' => 'integer',
            'manager_rating' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(PerformanceReview::class, 'performance_review_id');
    }
}
