<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. What was taught in a class on a day — done, partly or not —
 * linked to its plan and/or curriculum lesson. See docs/academics/lesson-plans.md.
 */
#[Fillable(['staff_id', 'class_id', 'book_id', 'curriculum_lesson_id', 'lesson_plan_id', 'taught_on', 'title', 'status', 'homework', 'note', 'created_by'])]
class TeachingLog extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    public const STATUSES = ['done', 'partial', 'not_done'];

    protected function casts(): array
    {
        return ['taught_on' => 'date'];
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

    public function auditModule(): string
    {
        return 'Academic';
    }

    public function auditDisplayName(): string
    {
        return $this->title;
    }
}
