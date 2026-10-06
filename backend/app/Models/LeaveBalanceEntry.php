<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. Days added to or taken from a staff member's yearly balance
 * of a leave type beyond their policy (HRM > Leave Management): HR's manual
 * `adjustment`, or the `carry_forward` of unused days from the year before
 * (one per staff, type and year), usable up to `expires_on` when set.
 */
#[Fillable(['staff_id', 'leave_type_id', 'year', 'kind', 'days', 'expires_on', 'note', 'created_by'])]
class LeaveBalanceEntry extends Model
{
    use Auditable;

    public const KIND_ADJUSTMENT = 'adjustment';

    public const KIND_CARRY_FORWARD = 'carry_forward';

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'days' => 'float',
            'expires_on' => 'date',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function auditModule(): string
    {
        return 'Leave';
    }
}
