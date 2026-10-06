<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Audit\AuditAction;
use Database\Factories\ManpowerRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tenant-owned. A request to hire (HRM > Recruitment > Manpower request) —
 * same pending/approved/rejected workflow as ResignationRequest, decided in
 * E-Approvals (with an approval flow if one is set for it). Approving one
 * is purely a status change; HR then opens a job for it.
 *
 * @property string $status
 * @property int $requested_by
 */
#[Fillable([
    'department_id', 'position_id', 'job_title', 'headcount', 'employment_type', 'needed_by',
    'reason', 'requirements', 'requested_by', 'status', 'decision_reason', 'decided_by', 'decided_at',
])]
class ManpowerRequest extends Model
{
    /** @use HasFactory<ManpowerRequestFactory> */
    use Auditable, HasFactory, SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const EMPLOYMENT_TYPES = ['full_time', 'part_time', 'contract', 'internship'];

    protected $connection = 'tenant';

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'headcount' => 1,
    ];

    protected function casts(): array
    {
        return [
            'department_id' => 'integer',
            'position_id' => 'integer',
            'headcount' => 'integer',
            'requested_by' => 'integer',
            'needed_by' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    /** "MP-000012" — how it's referred to in the Approvals queue and notifications. */
    public function reference(): string
    {
        return 'MP-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** The jobs HR opened for it (see JobPosition). */
    public function jobPositions(): HasMany
    {
        return $this->hasMany(JobPosition::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function auditModule(): string
    {
        return 'Recruitment';
    }

    public function auditDisplayName(): string
    {
        return "{$this->reference()}: {$this->job_title}";
    }

    protected function auditActionForDirty(array $dirty): string
    {
        return array_key_exists('status', $dirty) ? AuditAction::STATUS_CHANGE : AuditAction::UPDATE;
    }

    protected function auditDescriptionForChange(string $action, array $old, array $new): ?string
    {
        if ($action === AuditAction::STATUS_CHANGE) {
            return "Changed manpower request {$this->reference()} status from {$old['status']} to {$new['status']}";
        }

        return null;
    }
}
