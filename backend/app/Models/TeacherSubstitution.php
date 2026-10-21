<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. Who covers a class on a day its teacher is away (HRM > Teacher > Substitute).
 * See docs/hrm/teachers.md.
 */
#[Fillable(['class_id', 'absent_staff_id', 'substitute_staff_id', 'date', 'start_time', 'end_time', 'book_id', 'reason', 'created_by'])]
class TeacherSubstitution extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    /** @return BelongsTo<SchoolClass, $this> */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /** @return BelongsTo<Staff, $this> */
    public function absentStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'absent_staff_id');
    }

    /** @return BelongsTo<Staff, $this> */
    public function substituteStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'substitute_staff_id');
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class, 'book_id');
    }

    public function auditModule(): string
    {
        return 'HRM';
    }

    public function auditDisplayName(): string
    {
        return (string) 'Substitute teacher';
    }
}
