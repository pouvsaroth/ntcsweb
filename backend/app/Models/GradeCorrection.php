<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. A requested change to one cell of an approved result sheet,
 * with its reason — decided through eApprovals (DocumentType::GRADE_CORRECTION).
 * Approving it changes the entry, writes a GradeEntryHistory row and
 * recalculates the sheet. See docs/academics/gradebook.md.
 *
 * @property string $status
 * @property int $result_sheet_id
 * @property int|null $requested_by
 */
#[Fillable([
    'result_sheet_id', 'student_id', 'assessment_component_id', 'occurrence', 'competency_criterion_id',
    'old_score', 'old_absent', 'old_level_id', 'new_score', 'new_absent', 'new_level_id',
    'reason', 'status', 'requested_by',
])]
class GradeCorrection extends Model
{
    use Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'old_score' => 'decimal:2', 'new_score' => 'decimal:2', 'old_absent' => 'boolean', 'new_absent' => 'boolean',
            'occurrence' => 'integer', 'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ResultSheet, $this> */
    public function sheet(): BelongsTo
    {
        return $this->belongsTo(ResultSheet::class, 'result_sheet_id');
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<AssessmentComponent, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(AssessmentComponent::class, 'assessment_component_id');
    }

    /** @return BelongsTo<CompetencyCriterion, $this> */
    public function criterion(): BelongsTo
    {
        return $this->belongsTo(CompetencyCriterion::class, 'competency_criterion_id');
    }

    public function auditModule(): string
    {
        return 'Academic';
    }
}
