<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. A whole-class make-up session for a not-held class session
 * (Teachers > Substitute). See docs/hrm/teachers.md.
 */
#[Fillable(['class_id', 'teacher_substitution_id', 'date', 'start_time', 'end_time', 'staff_id', 'note', 'created_by'])]
class ClassMakeupSession extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    /** @return BelongsTo<SchoolClass, $this> */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /** @return BelongsTo<TeacherSubstitution, $this> */
    public function substitution(): BelongsTo
    {
        return $this->belongsTo(TeacherSubstitution::class, 'teacher_substitution_id');
    }

    /** @return BelongsTo<Staff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function auditModule(): string
    {
        return 'HRM';
    }

    public function auditDisplayName(): string
    {
        return 'Make-up class';
    }
}
