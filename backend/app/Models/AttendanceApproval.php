<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. A manager's sign-off of one staff member's month ("YYYY-MM")
 * — HRM > Attendance & Time > Attendance approval. While it exists, that
 * month is locked; deleting it (unlocking) opens it again.
 */
#[Fillable(['staff_id', 'month', 'note', 'approved_by'])]
class AttendanceApproval extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return ['staff_id' => 'integer', 'approved_by' => 'integer'];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function auditModule(): string
    {
        return 'Attendance';
    }

    public function auditDisplayName(): string
    {
        return "{$this->staff?->fullName()}: {$this->month}";
    }
}
