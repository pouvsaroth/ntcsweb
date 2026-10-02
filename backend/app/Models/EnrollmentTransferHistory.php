<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per class/table/course change on an Enrollment — see the
 * migration's docblock. Written exclusively by
 * EnrollmentService::recordTransfer(), never updated or deleted afterward.
 *
 * @property int $enrollment_id
 * @property int|null $from_class_id
 * @property int|null $to_class_id
 * @property int|null $from_table_id
 * @property int|null $to_table_id
 * @property int|null $from_course_package_id
 * @property int|null $to_course_package_id
 */
#[Fillable([
    'enrollment_id', 'from_class_id', 'to_class_id', 'from_table_id', 'to_table_id',
    'from_course_package_id', 'to_course_package_id', 'changed_by',
])]
class EnrollmentTransferHistory extends Model
{
    protected $connection = 'tenant';

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function fromClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'from_class_id');
    }

    public function toClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'to_class_id');
    }

    public function fromTable(): BelongsTo
    {
        return $this->belongsTo(ClassroomTable::class, 'from_table_id');
    }

    public function toTable(): BelongsTo
    {
        return $this->belongsTo(ClassroomTable::class, 'to_table_id');
    }

    public function fromCoursePackage(): BelongsTo
    {
        return $this->belongsTo(CoursePackage::class, 'from_course_package_id');
    }

    public function toCoursePackage(): BelongsTo
    {
        return $this->belongsTo(CoursePackage::class, 'to_course_package_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
