<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. A degree, certificate or skill of a teacher (HRM > Teacher > Qualification).
 * See docs/hrm/teachers.md.
 */
#[Fillable(['staff_id', 'type', 'level', 'title', 'institution', 'year', 'note'])]
class TeacherQualification extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    public const TYPES = ['degree', 'certificate', 'skill'];

    public const LEVELS = ['high_school', 'diploma', 'bachelor', 'master', 'doctorate', 'other'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
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
        return (string) $this->title;
    }
}
