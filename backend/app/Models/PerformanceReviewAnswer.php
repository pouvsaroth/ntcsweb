<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. A review's own copy of one evaluation-form question, with
 * the staff member's and the manager's answer — a 1–5 rating or words.
 */
#[Fillable(['performance_review_id', 'evaluation_form_question_id', 'section', 'question', 'type', 'is_required', 'sort_order', 'self_rating', 'self_answer', 'manager_rating', 'manager_answer'])]
class PerformanceReviewAnswer extends Model
{
    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'sort_order' => 'integer',
            'self_rating' => 'integer',
            'manager_rating' => 'integer',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(PerformanceReview::class, 'performance_review_id');
    }
}
