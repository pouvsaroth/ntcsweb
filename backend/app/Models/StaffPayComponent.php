<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\StaffPayComponentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. An allowance, bonus or deduction given to one staff member
 * (HRM > Payroll > Allowances / Bonuses / Deductions) — its monthly amount
 * in their salary currency (or a percentage), either recurring from
 * `starts_on` to `ends_on` (open-ended when null) or once, paid in the
 * payroll covering `starts_on`. One for a component their salary structure
 * already has replaces the structure's amount for them.
 */
#[Fillable(['staff_id', 'payroll_component_id', 'amount', 'recurrence', 'starts_on', 'ends_on', 'note', 'created_by'])]
class StaffPayComponent extends Model
{
    /** @use HasFactory<StaffPayComponentFactory> */
    use Auditable, HasFactory;

    public const RECURRING = 'recurring';

    public const ONCE = 'once';

    public const RECURRENCES = [self::RECURRING, self::ONCE];

    protected $connection = 'tenant';

    protected $attributes = [
        'recurrence' => self::RECURRING,
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayrollComponent::class, 'payroll_component_id');
    }

    public function auditModule(): string
    {
        return 'Payroll';
    }
}
