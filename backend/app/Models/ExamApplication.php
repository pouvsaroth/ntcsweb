<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
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
 * @property int $tenant_id
 * @property int $student_id
 * @property int $enrollment_id
 * @property string $status
 */
#[Fillable(['student_id', 'enrollment_id', 'exam_date', 'exam_time', 'table_no', 'fee_amount', 'fee_currency', 'student_marked_paid_at', 'status', 'decision_reason', 'decided_by', 'decided_at'])]
class ExamApplication extends Model
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

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
        return "{$this->student?->fullName()}: {$this->exam_date?->toDateString()}";
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
