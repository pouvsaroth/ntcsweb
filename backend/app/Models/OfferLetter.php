<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Audit\AuditAction;
use Database\Factories\OfferLetterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. A job offer to an applicant (HRM > Recruitment > Offer
 * letter) — draft → sent → accepted / declined (or withdrawn by the
 * school). An accepted offer is turned into a Staff record; see
 * OfferLetterService::hire().
 *
 * @property string $status
 */
#[Fillable([
    'applicant_id', 'job_position_id', 'position_title', 'department_id', 'employment_type', 'salary', 'salary_currency',
    'start_date', 'probation_months', 'expires_on', 'benefits', 'terms', 'status', 'sent_at', 'responded_at', 'hired_staff_id', 'created_by',
])]
class OfferLetter extends Model
{
    /** @use HasFactory<OfferLetterFactory> */
    use Auditable, HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_SENT, self::STATUS_ACCEPTED, self::STATUS_DECLINED, self::STATUS_WITHDRAWN];

    protected $connection = 'tenant';

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'salary_currency' => 'USD',
    ];

    protected function casts(): array
    {
        return [
            'applicant_id' => 'integer',
            'job_position_id' => 'integer',
            'department_id' => 'integer',
            'salary' => 'decimal:2',
            'start_date' => 'date',
            'expires_on' => 'date',
            'probation_months' => 'integer',
            'sent_at' => 'datetime',
            'responded_at' => 'datetime',
            'hired_staff_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    /** "OL-000004". */
    public function reference(): string
    {
        return 'OL-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function jobPosition(): BelongsTo
    {
        return $this->belongsTo(JobPosition::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function hiredStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'hired_staff_id');
    }

    public function auditModule(): string
    {
        return 'Recruitment';
    }

    public function auditDisplayName(): string
    {
        return "{$this->reference()}: {$this->applicant?->fullName()}";
    }

    protected function auditActionForDirty(array $dirty): string
    {
        return array_key_exists('status', $dirty) ? AuditAction::STATUS_CHANGE : AuditAction::UPDATE;
    }

    protected function auditDescriptionForChange(string $action, array $old, array $new): ?string
    {
        return $action === AuditAction::STATUS_CHANGE
            ? "Changed offer letter {$this->reference()} status from {$old['status']} to {$new['status']}"
            : null;
    }
}
