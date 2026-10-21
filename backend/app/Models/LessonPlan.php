<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned. A teacher's plan for one lesson of a class — objectives,
 * materials, activities, homework, files — reviewed before it is taught.
 * See docs/academics/lesson-plans.md.
 *
 * @property int $staff_id
 * @property string $status
 */
#[Fillable([
    'staff_id', 'class_id', 'book_id', 'curriculum_lesson_id', 'planned_date', 'title',
    'objectives', 'materials', 'activities', 'homework', 'homework_due', 'created_by',
])]
class LessonPlan extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const RETURNED = 'returned';

    protected function casts(): array
    {
        return [
            'planned_date' => 'date',
            'homework_due' => 'date',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Staff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
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

    /** @return BelongsTo<CurriculumLesson, $this> */
    public function curriculumLesson(): BelongsTo
    {
        return $this->belongsTo(CurriculumLesson::class);
    }

    /** @return HasMany<LessonPlanFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(LessonPlanFile::class);
    }

    /** @return HasMany<TeachingLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(TeachingLog::class);
    }

    /** Its teacher can still change it. */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::DRAFT, self::RETURNED], true);
    }

    public function auditModule(): string
    {
        return 'Academic';
    }

    public function auditDisplayName(): string
    {
        return $this->title;
    }
}
