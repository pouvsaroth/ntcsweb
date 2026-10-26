<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. One student of a remedial session: their percent in the
 * subject when added, whether they came, and the outcome.
 * See docs/academics/gradebook.md, 7e.
 */
#[Fillable(['remedial_session_id', 'student_id', 'percent_before', 'attended', 'outcome', 'remark'])]
class RemedialSessionStudent extends Model
{
    public const OUTCOMES = ['improved', 'no_change', 'needs_more'];

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return ['percent_before' => 'decimal:2', 'attended' => 'boolean'];
    }

    /** @return BelongsTo<RemedialSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(RemedialSession::class, 'remedial_session_id');
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
