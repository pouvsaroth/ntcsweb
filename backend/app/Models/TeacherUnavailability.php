<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. Hours a teacher cannot teach: every week on a day, or on given dates (HRM > Teacher > Availability).
 * See docs/hrm/teachers.md.
 */
#[Fillable(['staff_id', 'kind', 'day_of_week', 'from_date', 'to_date', 'start_time', 'end_time', 'reason'])]
class TeacherUnavailability extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    public const WEEKLY = 'weekly';

    public const DATES = 'dates';

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'from_date' => 'date',
            'to_date' => 'date',
        ];
    }

    /** @return BelongsTo<Staff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    public function auditModule(): string
    {
        return 'HRM';
    }

    public function auditDisplayName(): string
    {
        return (string) 'Teacher unavailability';
    }
}
