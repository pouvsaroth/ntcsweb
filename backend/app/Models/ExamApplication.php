<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Audit\AuditAction;
use Database\Factories\ExamApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A student's own self-submitted application to sit an exam for one of
 * their own enrollments — see the migration's docblock for the
 * fee-snapshot and payment-declaration reasoning. Unlike LeaveRequest,
 * approving one has no side effect beyond the row itself: exam-day
 * logistics (which room, proctor, etc.) stay a manual, offline school
 * process in v1.
 *
 * An admin can also create/edit one of these directly (the "Exam
 * Application" tab) rather than only approving/rejecting a student's own
 * submission — see ExamApplicationController and the 2026_09_15_030000
 * migration's docblock for `file_code`/`exam_time_out`/`remark`/`sold_at`/
 * `received_at`/`paid_back_at`.
 *
 * `book_id`/`classroom_id`/`table_id` are the admin form's dropdown-driven
 * picks — see the 2026_09_15_040000 migration's docblock for why `table_no`
 * (the student self-service flow's free-text field) is left untouched
 * alongside `table_id` rather than replaced by it.
 *
 * @property int $student_id
 * @property int $enrollment_id
 * @property string $status
 */
#[Fillable([
    'student_id', 'enrollment_id', 'book_id', 'file_code', 'exam_date', 'exam_time', 'exam_time_out',
    'table_no', 'classroom_id', 'table_id', 'fee_amount', 'fee_currency', 'student_marked_paid_at',
    'status', 'decision_reason', 'remark', 'decided_by', 'decided_at', 'sold_at', 'received_at', 'paid_back_at',
])]
class ExamApplication extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $connection = 'tenant';

    /** @use HasFactory<ExamApplicationFactory> */
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'exam_date' => 'date',
            'fee_amount' => 'decimal:2',
            'student_marked_paid_at' => 'datetime',
            'decided_at' => 'datetime',
            'sold_at' => 'datetime',
            'received_at' => 'datetime',
            'paid_back_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(ClassroomTable::class, 'table_id');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', self::STATUS_PENDING);
    }

    public function auditModule(): string
    {
        return 'Exam Applications';
    }

    public function auditDisplayName(): string
    {
        return "{$this->student?->fullName()}: {$this->exam_date?->format('d-m-Y')}";
    }

    protected function auditActionForDirty(array $dirty): string
    {
        return array_key_exists('status', $dirty) ? AuditAction::STATUS_CHANGE : AuditAction::UPDATE;
    }

    protected function auditDescriptionForChange(string $action, array $old, array $new): ?string
    {
        if ($action === AuditAction::STATUS_CHANGE) {
            return "Changed exam application for {$this->student?->fullName()} status from {$old['status']} to {$new['status']}";
        }

        return null;
    }
}
