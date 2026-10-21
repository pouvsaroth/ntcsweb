<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. A day — or run of days — on the school calendar: school
 * opening or closing, exam days, an event, a meeting. Holidays are their own
 * list (Holiday); the calendar shows both. See docs/academics/school-setup.md.
 *
 * @property string $title
 * @property string $type
 */
#[Fillable(['academic_year_id', 'title', 'type', 'start_date', 'end_date', 'description'])]
class SchoolCalendarEvent extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    public const TYPES = ['opening', 'closing', 'exam', 'event', 'meeting', 'other'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
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
        return $this->title;
    }
}
