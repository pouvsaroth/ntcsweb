<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. One interviewer's evaluation of one Interview (HRM >
 * Recruitment > Interview evaluation): a 1–5 score per CRITERIA entry,
 * their average, and a recommendation.
 *
 * @property array<string, int> $scores
 */
#[Fillable(['interview_id', 'evaluator_id', 'scores', 'overall_score', 'recommendation', 'strengths', 'concerns'])]
class InterviewEvaluation extends Model
{
    use Auditable;

    public const CRITERIA = ['communication', 'knowledge', 'experience', 'attitude', 'teamwork'];

    public const RECOMMENDATIONS = ['hire', 'maybe', 'no_hire'];

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'interview_id' => 'integer',
            'evaluator_id' => 'integer',
            'scores' => 'array',
            'overall_score' => 'decimal:2',
        ];
    }

    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluator_id');
    }

    public function auditModule(): string
    {
        return 'Recruitment';
    }

    public function auditDisplayName(): string
    {
        return "{$this->interview?->applicant?->fullName()}: {$this->recommendation}";
    }
}
