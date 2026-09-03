<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\SchoolClassFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tenant-owned. A scheduled teaching group — a section students enroll into,
 * taught by one teacher in one room (e.g. "Excel Basics — Evening Batch 12").
 *
 * Named `SchoolClass`, not `Class`: `class` is a reserved word in PHP and
 * cannot name a class at all. The table is still plainly `classes`.
 *
 * Optionally linked to an Academic Program — a class is purely a
 * schedule/room/teacher grouping, so the link is nullable and never gates
 * which course packages can be enrolled into it (see EnrollmentService).
 *
 * @property int $tenant_id
 * @property string $name
 * @property string $status
 */
#[Fillable(['teacher_id', 'classroom_id', 'academic_program_id', 'name', 'code', 'capacity', 'start_date', 'end_date', 'status'])]
class SchoolClass extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    /** @use HasFactory<SchoolClassFactory> */
    protected $table = 'classes';

    public const STATUS_UPCOMING = 'upcoming';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /** PHP-level mirror of the column's DB default — see Building for why. */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function academicProgram(): BelongsTo
    {
        return $this->belongsTo(AcademicProgram::class);
    }

    public function coursePackages(): BelongsToMany
    {
        return $this->belongsToMany(CoursePackage::class, 'class_course_package', 'class_id', 'course_package_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ClassSchedule::class, 'class_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'class_id');
    }

    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'class_book', 'class_id', 'book_id');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }
}
