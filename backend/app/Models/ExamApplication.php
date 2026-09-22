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
use Illuminate\Database\Eloquent\Relations\HasOne;
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
    'student_id', 'enrollment_id', 'book_id', 'retake_of_id', 'file_code', 'exam_date', 'exam_time', 'exam_time_out',
    'table_no', 'classroom_id', 'table_id', 'fee_amount', 'fee_currency', 'student_marked_paid_at',
    'status', 'decision_reason', 'remark', 'decided_by', 'decided_at', 'sold_at', 'received_at', 'paid_back_at',
])]
class ExamApplication extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $connection = 'tenant';

    /** @use HasFactory<ExamApplicationFactory> */
    // A row "Send to Exam" (ClassStudents.vue's roster action) creates —
    // exam-day logistics may already be set, but nobody has actually
    // applied yet. Never appears in the "Exam Application Approval" tab
    // (that queries status=pending) until it becomes PENDING, either via
    // the student applying online or an admin submitting on their behalf
    // — see ExamApplicationService::applyOnline().
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    // The student was sent to exam (still DRAFT) but doesn't want to sit
    // it — see ExamApplicationService::markNotExam(). Distinct from
    // REJECTED: rejected means they applied and an admin turned it down;
    // this means they never applied at all.
    public const STATUS_NOT_EXAM = 'not_exam';

    // A retake, auto-created by ExamScoreService::record() when a score is
    // saved with its `make_up` flag set — see `retake_of_id`. Treated as
    // already-approved for scoring purposes (see scopeScoreable()): no
    // separate re-approval step, since checking the box and saving *is*
    // the approval.
    public const STATUS_MAKE_UP = 'make_up';

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

    /** See ExamScore — only ever set on an approved (or make-up) application. */
    public function score(): HasOne
    {
        return $this->hasOne(ExamScore::class);
    }

    /** The original application a make-up was generated from — null on every ordinary row. */
    public function retakeOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retake_of_id');
    }

    /** The make-up generated from this row, if a score was ever saved with the `make_up` flag — see ExamScoreService::record(). At most one: re-checking the box is a no-op, not a second retake. */
    public function retake(): HasOne
    {
        return $this->hasOne(self::class, 'retake_of_id');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', self::STATUS_APPROVED);
    }

    /**
     * Approved or make-up — the two statuses ExamScoreService lets a score
     * be recorded against. A make-up skips the separate approval step
     * entirely (see STATUS_MAKE_UP's docblock).
     *
     * @param  Builder<static>  $query
     */
    public function scopeScoreable(Builder $query): void
    {
        $query->whereIn('status', [self::STATUS_APPROVED, self::STATUS_MAKE_UP]);
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
