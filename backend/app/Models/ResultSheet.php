<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned. The scores of one class in one subject for one term, entered
 * under one assessment setup. Draft until submitted; pending and approved
 * sheets are locked (changes go through a grade correction, step 7c).
 * See docs/academics/gradebook.md.
 *
 * @property int $class_id
 * @property int $book_id
 * @property int $academic_term_id
 * @property int|null $assessment_scheme_id
 * @property string $status
 */
#[Fillable(['class_id', 'book_id', 'academic_term_id', 'assessment_scheme_id', 'status', 'requested_by'])]
class ResultSheet extends Model
{
    use Auditable;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_RETURNED = 'returned';

    /** Statuses whose scores may still be typed in. */
    public const EDITABLE = [self::STATUS_DRAFT, self::STATUS_RETURNED];

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE, true);
    }

    /** @return BelongsTo<SchoolClass, $this> */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /** @return BelongsTo<AcademicTerm, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'academic_term_id');
    }

    /** @return BelongsTo<AssessmentScheme, $this> */
    public function scheme(): BelongsTo
    {
        return $this->belongsTo(AssessmentScheme::class, 'assessment_scheme_id');
    }

    /** @return HasMany<GradeEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(GradeEntry::class);
    }

    /** @return HasMany<SubjectResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(SubjectResult::class);
    }

    public function auditModule(): string
    {
        return 'Academic';
    }
}
