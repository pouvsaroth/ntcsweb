<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\PayrollComponentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned. A named pay item (HRM > Payroll > Allowances / Bonuses /
 * Deductions) — Transport, Housing, Year-end bonus, Uniform, ... Either a
 * fixed amount or a percentage of basic salary; the amount itself lives on
 * a salary structure's item or a staff member's own assignment.
 *
 * `affects_tax` / `affects_social_security`: for an allowance or bonus,
 * whether it's part of the pay those are worked out on; for a deduction,
 * whether it comes off before they are.
 */
#[Fillable(['kind', 'code', 'name', 'calculation', 'affects_tax', 'affects_social_security', 'description', 'is_active'])]
class PayrollComponent extends Model
{
    /** @use HasFactory<PayrollComponentFactory> */
    use Auditable, HasFactory;

    public const KIND_ALLOWANCE = 'allowance';

    public const KIND_BONUS = 'bonus';

    public const KIND_DEDUCTION = 'deduction';

    public const KINDS = [self::KIND_ALLOWANCE, self::KIND_BONUS, self::KIND_DEDUCTION];

    public const CALCULATION_FIXED = 'fixed';

    public const CALCULATION_PERCENT_OF_BASIC = 'percent_of_basic';

    public const CALCULATIONS = [self::CALCULATION_FIXED, self::CALCULATION_PERCENT_OF_BASIC];

    protected $connection = 'tenant';

    protected $attributes = [
        'calculation' => self::CALCULATION_FIXED,
        'affects_tax' => true,
        'affects_social_security' => false,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'affects_tax' => 'boolean',
            'affects_social_security' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function structureItems(): HasMany
    {
        return $this->hasMany(SalaryStructureItem::class);
    }

    public function staffAssignments(): HasMany
    {
        return $this->hasMany(StaffPayComponent::class);
    }

    public function auditModule(): string
    {
        return 'Payroll';
    }
}
