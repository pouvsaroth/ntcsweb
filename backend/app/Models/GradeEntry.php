<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. One student's score (or absent) for one component and one
 * time it is given — or, in competency mode, the level for one criterion.
 * See docs/academics/gradebook.md.
 *
 * @property int|null $assessment_component_id
 * @property int|null $competency_criterion_id
 */
#[Fillable([
    'result_sheet_id', 'student_id', 'assessment_component_id', 'occurrence', 'score', 'absent',
    'competency_criterion_id', 'competency_level_id', 'remark', 'updated_by',
])]
class GradeEntry extends Model
{
    protected $connection = 'tenant';

    protected function casts(): array
    {
        return ['score' => 'decimal:2', 'absent' => 'boolean', 'occurrence' => 'integer'];
    }

    /** @return BelongsTo<ResultSheet, $this> */
    public function sheet(): BelongsTo
    {
        return $this->belongsTo(ResultSheet::class, 'result_sheet_id');
    }
}
