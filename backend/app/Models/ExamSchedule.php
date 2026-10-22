<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. One subject's exam for every class of a grade, or for one
 * class: when, where and who watches it. See docs/academics/gradebook.md.
 *
 * @property int|null $grade_level_id
 * @property int|null $class_id
 */
#[Fillable([
    'academic_year_id', 'academic_term_id', 'assessment_component_id', 'grade_level_id', 'class_id', 'book_id',
    'exam_date', 'start_time', 'end_time', 'classroom_id', 'invigilator_staff_id', 'notes',
])]
class ExamSchedule extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return ['exam_date' => 'date'];
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /** @return BelongsTo<AcademicTerm, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'academic_term_id');
    }

    /** @return BelongsTo<AssessmentComponent, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(AssessmentComponent::class, 'assessment_component_id');
    }

    /** @return BelongsTo<GradeLevel, $this> */
    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
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

    /** @return BelongsTo<Classroom, $this> */
    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    /** @return BelongsTo<Staff, $this> */
    public function invigilator(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'invigilator_staff_id');
    }

    public function auditModule(): string
    {
        return 'Academic';
    }
}
