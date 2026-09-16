<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Audit\AuditAction;
use Database\Factories\ResignationRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A staff member's own self-submitted resignation request — same
 * pending/approved/rejected workflow as LeaveRequest, but always
 * staff-owned (see the migration's docblock). Approving one is purely a
 * status change; it does not itself change Staff::status — a school admin
 * still updates that separately from the Staff record if/when the
 * resignation actually takes effect.
 *
 * @property int $staff_id
 * @property string $status
 */
#[Fillable(['staff_id', 'resignation_date', 'reason', 'status', 'decision_reason', 'decided_by', 'decided_at'])]
class ResignationRequest extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    /** @use HasFactory<ResignationRequestFactory> */
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
            'resignation_date' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
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
        return 'Staff';
    }

    public function auditDisplayName(): string
    {
        return "{$this->staff?->fullName()}: {$this->resignation_date?->format('d-m-Y')}";
    }

    protected function auditActionForDirty(array $dirty): string
    {
        return array_key_exists('status', $dirty) ? AuditAction::STATUS_CHANGE : AuditAction::UPDATE;
    }

    protected function auditDescriptionForChange(string $action, array $old, array $new): ?string
    {
        if ($action === AuditAction::STATUS_CHANGE) {
            return "Changed resignation request for {$this->staff?->fullName()} status from {$old['status']} to {$new['status']}";
        }

        return null;
    }
}
