<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned. Extra teaching for students below the pass mark in one
 * subject of a class (Examination > Remedial Teaching). See
 * docs/academics/gradebook.md, 7e.
 *
 * @property int $class_id
 * @property int $book_id
 * @property int|null $academic_term_id
 * @property int|null $staff_id
 */
#[Fillable(['class_id', 'book_id', 'academic_term_id', 'staff_id', 'session_date', 'start_time', 'end_time', 'topic', 'notes', 'created_by'])]
class RemedialSession extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return ['session_date' => 'date'];
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

    /** @return BelongsTo<Staff, $this> */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    /** @return HasMany<RemedialSessionStudent, $this> */
    public function students(): HasMany
    {
        return $this->hasMany(RemedialSessionStudent::class);
    }

    public function auditModule(): string
    {
        return 'Academic';
    }

    public function auditDisplayName(): string
    {
        return 'Remedial session: '.$this->topic;
    }
}
