<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. One thing scored in a term (monthly test, semester exam…),
 * its maximum, its weight (%) in the term result, and how many times a term
 * it is given (averaged). See docs/academics/gradebook.md.
 */
#[Fillable(['assessment_scheme_id', 'name', 'name_km', 'kind', 'max_score', 'weight', 'occurrences', 'sort_order'])]
class AssessmentComponent extends Model
{
    public const KINDS = ['monthly', 'quiz', 'assignment', 'midterm', 'exam', 'project', 'other'];

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return ['max_score' => 'decimal:2', 'weight' => 'decimal:2', 'occurrences' => 'integer', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<AssessmentScheme, $this> */
    public function scheme(): BelongsTo
    {
        return $this->belongsTo(AssessmentScheme::class, 'assessment_scheme_id');
    }
}
