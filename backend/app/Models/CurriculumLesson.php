<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. One lesson of a subject (Book) at a grade level, in order,
 * with its hours — the curriculum (docs/academics/school-setup.md).
 *
 * @property int $grade_level_id
 * @property int $book_id
 * @property string $title
 */
#[Fillable(['grade_level_id', 'book_id', 'title', 'hours', 'description', 'sort_order'])]
class CurriculumLesson extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'hours' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<GradeLevel, $this> */
    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
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
