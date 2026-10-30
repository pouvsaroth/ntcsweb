<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. One school or university a staff member studied at
 * (HRM > Staff > Education). See docs/hrm/staff-education.md.
 */
#[Fillable(['staff_id', 'level', 'institution', 'major', 'country', 'start_year', 'end_year', 'note'])]
class StaffEducation extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $table = 'staff_educations';

    public const LEVELS = ['high_school', 'diploma', 'associate', 'bachelor', 'master', 'doctorate', 'other'];

    protected function casts(): array
    {
        return [
            'start_year' => 'integer',
            'end_year' => 'integer',
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
        return (string) $this->institution;
    }
}
