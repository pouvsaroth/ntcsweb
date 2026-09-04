<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Audit\AuditAction;
use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Tenant-owned. Student <-> Class <-> Book: which specific book a student
 * picked from that class session's book menu (`SchoolClass::books()`), and
 * what they're being charged for it. Modelled as a full Eloquent model (its
 * own `id`, queryable on its own) rather than a `belongsToMany` pivot, so it
 * can carry that metadata and be looked up directly without always going
 * through one side's relation.
 *
 * A class is a shared session (teacher, room, schedule), not a shared
 * curriculum — two students in the same session can each be on a different
 * book at a different fee, so `book_id`/`fee` live here, per student, rather
 * than on the class itself. `fee` is a snapshot taken at enrollment time
 * (see StoreEnrollmentRequest), not a live read of `books.fee` — a later
 * catalog price change must never retroactively alter what an
 * already-enrolled student owes.
 *
 * @property int $tenant_id
 * @property int $student_id
 * @property int $class_id
 * @property int|null $table_id Which physical table in the class's classroom this student sits at -- see ClassroomTable.
 * @property int|null $book_id
 * @property int|null $course_package_id
 * @property int|null $academic_program_id
 * @property int|null $study_mode_id
 * @property string $fee
 * @property string $status
 * @property string|null $status_reason
 */
#[Fillable([
    'student_id', 'class_id', 'table_id', 'book_id', 'course_package_id', 'academic_program_id', 'study_mode_id',
    'enrolled_at', 'fee', 'fee_type', 'status', 'status_reason', 'status_effective_date',
])]
class Enrollment extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    /** @use HasFactory<EnrollmentFactory> */
    public const STATUS_NOT_STARTED = 'not_started';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXAM_READY = 'exam_ready';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ABANDONED = 'abandoned';

    public const STATUS_STOPPED = 'stopped';

    public const STATUS_SUSPENDED = 'suspended';

    /**
     * Not a status an admin ever picks from the status-management menu —
     * purely internal bookkeeping for a row superseded by
     * EnrollmentService::cancel()/transferClass(). See STATUSES_MANAGEABLE
     * for the set that actually appears in that menu.
     */
    public const STATUS_DROPPED = 'dropped';

    /**
     * The statuses selectable from the "manage status" menu — everything
     * except STATUS_DROPPED, which only ever happens as a side effect of
     * cancelling or transferring, never a direct choice.
     */
    public const STATUSES_MANAGEABLE = [
        self::STATUS_NOT_STARTED, self::STATUS_ACTIVE, self::STATUS_EXAM_READY, self::STATUS_COMPLETED,
        self::STATUS_ABANDONED, self::STATUS_STOPPED, self::STATUS_SUSPENDED,
    ];

    /** Which of STATUSES_MANAGEABLE require a reason + effective date — see EnrollmentService::changeStatus(). */
    public const STATUSES_REQUIRING_REASON = [self::STATUS_ABANDONED, self::STATUS_STOPPED, self::STATUS_SUSPENDED];

    /**
     * Set by EnrollmentService::cancel()/transferClass() immediately before
     * calling update(), purely to let auditActionForDirty()/
     * auditDescriptionForChange() below tell "cancelled" apart from
     * "transferred" — both just set status=dropped, and a plain column-diff
     * can't otherwise know which one happened. Real PHP properties, not
     * Eloquent attributes — never persisted, never touch the `enrollments`
     * table.
     */
    public ?string $auditReason = null;

    public ?string $auditTransferToClass = null;

    /** PHP-level mirror of the column's DB default — see Building for why. */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'enrolled_at' => 'date',
            'fee' => 'decimal:2',
            'status_effective_date' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(ClassroomTable::class, 'table_id');
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function coursePackage(): BelongsTo
    {
        return $this->belongsTo(CoursePackage::class);
    }

    public function academicProgram(): BelongsTo
    {
        return $this->belongsTo(AcademicProgram::class);
    }

    public function studyMode(): BelongsTo
    {
        return $this->belongsTo(StudyMode::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(EnrollmentStatusHistory::class);
    }

    /** The InvoiceItem(s) billed for this enrollment — see InvoiceItem::reference(). */
    public function invoiceItems(): MorphMany
    {
        return $this->morphMany(InvoiceItem::class, 'reference');
    }

    /**
     * Whether any money has actually been received against this enrollment —
     * the gate EnrollmentService::transferClass() uses to decide whether the
     * student's COURSE (not just class/room) may still be changed. Computed
     * from the loaded relation when available (EnrollmentController eager
     * loads `invoiceItems.invoice` for exactly this reason) so listing a
     * page of enrollments doesn't fire one extra query per row.
     */
    public function isPaid(): bool
    {
        if ($this->relationLoaded('invoiceItems')) {
            return $this->invoiceItems->contains(fn (InvoiceItem $item) => (float) $item->invoice?->paid_amount > 0);
        }

        return $this->invoiceItems()->whereHas('invoice', fn (Builder $query) => $query->where('paid_amount', '>', 0))->exists();
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * No APPROVE/REJECT here: this system has no pending-approval workflow
     * for an enrollment today (see $this::STATUS_*) — only CREATE, UPDATE,
     * DELETE, and STATUS_CHANGE actually happen. Add them if/when an
     * approval step is introduced.
     */
    public function auditModule(): string
    {
        return 'Enrollments';
    }

    /**
     * @param  array<string, mixed>  $dirty
     */
    protected function auditActionForDirty(array $dirty): string
    {
        if (array_key_exists('status', $dirty) && $dirty['status'] === self::STATUS_DROPPED) {
            return $this->auditTransferToClass !== null ? AuditAction::ENROLLMENT_TRANSFERRED : AuditAction::ENROLLMENT_CANCELLED;
        }

        return array_key_exists('status', $dirty) ? AuditAction::STATUS_CHANGE : AuditAction::UPDATE;
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    protected function auditDescriptionForChange(string $action, array $old, array $new): ?string
    {
        if ($action === AuditAction::ENROLLMENT_CANCELLED) {
            return "Cancelled enrollment {$this->auditDisplayName()}".($this->auditReason ? ": {$this->auditReason}" : '');
        }

        if ($action === AuditAction::ENROLLMENT_TRANSFERRED) {
            return "Transferred enrollment {$this->auditDisplayName()} to class {$this->auditTransferToClass}";
        }

        if ($action === AuditAction::STATUS_CHANGE) {
            $description = "Changed enrollment {$this->auditDisplayName()} status from {$old['status']} to {$new['status']}";

            return $this->auditReason ? "{$description}: {$this->auditReason}" : $description;
        }

        return null;
    }
}
