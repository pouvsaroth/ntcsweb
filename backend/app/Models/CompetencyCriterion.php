<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tenant-owned. One thing a student is judged on in competency mode; no subject = every subject. See docs/academics/gradebook.md. */
#[Fillable(['assessment_scheme_id', 'book_id', 'name', 'name_km', 'sort_order'])]
class CompetencyCriterion extends Model
{
    protected $connection = 'tenant';

    protected $table = 'competency_criteria';

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
