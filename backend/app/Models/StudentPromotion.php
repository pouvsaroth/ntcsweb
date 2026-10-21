<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. What Promote students decided for one enrollment — promoted,
 * repeated or graduated — with the figures it saw. One per enrollment.
 * See docs/academics/school-setup.md.
 *
 * @property string $result
 */
#[Fillable([
    'student_id', 'from_enrollment_id', 'from_class_id', 'to_class_id', 'to_enrollment_id', 'academic_year_id',
    'result', 'met_rule', 'average', 'attendance_rate', 'failed_subjects', 'decided_by',
])]
class StudentPromotion extends Model
{
    protected $connection = 'tenant';

    public const PROMOTED = 'promoted';

    public const REPEATED = 'repeated';

    public const GRADUATED = 'graduated';

    protected function casts(): array
    {
        return [
            'met_rule' => 'boolean',
            'average' => 'decimal:2',
            'attendance_rate' => 'decimal:2',
            'failed_subjects' => 'integer',
        ];
    }

    /** @return BelongsTo<SchoolClass, $this> */
    public function toClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'to_class_id');
    }
}
