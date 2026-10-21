<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. One term / semester of an academic year ("Semester 1"),
 * with its own dates. See docs/academics/school-setup.md.
 *
 * @property int $academic_year_id
 * @property string $name
 */
#[Fillable(['academic_year_id', 'name', 'start_date', 'end_date', 'sort_order'])]
class AcademicTerm extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function auditModule(): string
    {
        return 'Academic';
    }

    public function auditDisplayName(): string
    {
        return $this->name;
    }
}
