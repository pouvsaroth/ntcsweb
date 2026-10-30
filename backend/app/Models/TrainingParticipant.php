<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. A staff member on a training: attendance and result
 * (Training Records) and the certificate they received (Certificates).
 * See docs/hrm/professional-development.md.
 */
#[Fillable(['training_id', 'staff_id', 'attendance', 'result', 'score', 'note', 'certificate_no', 'certificate_issued_on', 'certificate_expires_on', 'certificate_file_path', 'certificate_file_name', 'certificate_mime', 'certificate_size', 'teacher_qualification_id'])]
class TrainingParticipant extends Model
{
    use Auditable;

    public const ATTENDANCES = ['registered', 'attended', 'absent'];

    public const RESULTS = ['passed', 'failed'];

    protected $connection = 'tenant';

    protected $attributes = [
        'attendance' => 'registered',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'certificate_issued_on' => 'date',
            'certificate_expires_on' => 'date',
            'certificate_size' => 'integer',
        ];
    }

    /** @return BelongsTo<Training, $this> */
    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }

    /** @return BelongsTo<Staff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id')->withTrashed();
    }

    public function hasCertificate(): bool
    {
        return $this->certificate_issued_on !== null;
    }

    public function auditModule(): string
    {
        return 'HRM';
    }

    public function auditDisplayName(): string
    {
        return (string) ($this->certificate_no ?? $this->id);
    }
}
