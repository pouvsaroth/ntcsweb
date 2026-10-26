<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. A student's request to move one of their enrollments to
 * another class from a start date (ថ្ងៃចាប់ផ្តើមរៀន). Decided through
 * eApprovals (DocumentType::CLASS_CHANGE): an Approval Flow when the school
 * set one up, else whoever holds `class-change-requests.approve`. Approving
 * moves the enrollment (EnrollmentService::transferClass) and writes the
 * transfer history. See App\Services\Academic\ClassChangeRequestService.
 *
 * @property int $student_id
 * @property int $enrollment_id
 * @property int|null $from_class_id
 * @property int $to_class_id
 * @property string $status
 * @property int|null $requested_by
 */
#[Fillable(['student_id', 'enrollment_id', 'from_class_id', 'to_class_id', 'start_date', 'reason', 'status', 'requested_by'])]
class ClassChangeRequest extends Model
{
    use Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return ['start_date' => 'date', 'decided_at' => 'datetime'];
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** @return BelongsTo<SchoolClass, $this> */
    public function fromClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'from_class_id');
    }

    /** @return BelongsTo<SchoolClass, $this> */
    public function toClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'to_class_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function reference(): string
    {
        return 'CC-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function auditModule(): string
    {
        return 'Academic';
    }

    public function auditDisplayName(): string
    {
        return 'Class change request '.$this->reference();
    }
}
