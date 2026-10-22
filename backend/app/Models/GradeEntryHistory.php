<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-owned. One change to a cell of an approved result sheet: old → new,
 * why, by whom, through which grade correction. Never edited or deleted.
 * See docs/academics/gradebook.md.
 */
#[Fillable([
    'result_sheet_id', 'student_id', 'assessment_component_id', 'occurrence', 'competency_criterion_id',
    'old_score', 'old_absent', 'old_level_id', 'new_score', 'new_absent', 'new_level_id',
    'reason', 'grade_correction_id', 'changed_by', 'changed_at',
])]
class GradeEntryHistory extends Model
{
    public $timestamps = false;

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'old_score' => 'decimal:2', 'new_score' => 'decimal:2', 'old_absent' => 'boolean', 'new_absent' => 'boolean',
            'occurrence' => 'integer', 'changed_at' => 'datetime',
        ];
    }
}
