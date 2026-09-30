<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Audit\AuditAction;
use Database\Factories\MakeUpClassRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A student's own self-submitted make-up class request (ស្នើសុំរៀនសង) — same
 * pending/approved/rejected workflow as LeaveRequest, but always
 * student-owned (see the migration's docblock). Approving one is purely a
 * status change; scheduling the actual make-up session is still done by
 * the school.
 *
 * @property int $student_id
 * @property int $enrollment_id
 * @property string $status
 */
#[Fillable(['student_id', 'enrollment_id', 'from_date', 'to_date', 'from_time', 'to_time', 'status', 'decision_reason', 'decided_by', 'decided_at'])]
class MakeUpClassRequest extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    /** @use HasFactory<MakeUpClassRequestFactory> */
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $connection = 'tenant';

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
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
        return 'Academic';
    }

    public function auditDisplayName(): string
    {
        return "{$this->student?->fullName()}: {$this->from_date?->format('d-m-Y')} – {$this->to_date?->format('d-m-Y')}";
    }

    protected function auditActionForDirty(array $dirty): string
    {
        return array_key_exists('status', $dirty) ? AuditAction::STATUS_CHANGE : AuditAction::UPDATE;
    }

    protected function auditDescriptionForChange(string $action, array $old, array $new): ?string
    {
        if ($action === AuditAction::STATUS_CHANGE) {
            return "Changed make-up class request for {$this->student?->fullName()} status from {$old['status']} to {$new['status']}";
        }

        return null;
    }
}
