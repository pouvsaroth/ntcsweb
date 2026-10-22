<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. What one student's entries on a result sheet add up to —
 * recalculated on every save (App\Services\Academic\Gradebook\Calculator).
 * See docs/academics/gradebook.md.
 */
#[Fillable(['result_sheet_id', 'student_id', 'percent', 'score', 'band', 'passed', 'rank', 'complete'])]
class SubjectResult extends Model
{
    protected $connection = 'tenant';

    protected function casts(): array
    {
        return ['percent' => 'decimal:2', 'score' => 'decimal:2', 'passed' => 'boolean', 'rank' => 'integer', 'complete' => 'boolean'];
    }

    /** @return BelongsTo<ResultSheet, $this> */
    public function sheet(): BelongsTo
    {
        return $this->belongsTo(ResultSheet::class, 'result_sheet_id');
    }
}
