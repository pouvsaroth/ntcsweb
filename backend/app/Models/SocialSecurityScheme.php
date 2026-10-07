<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-owned. One NSSF contribution (HRM > Payroll > Social security) —
 * Occupational risk, Health care, Pension: a % of the monthly wage for the
 * staff member and for the school, the wage clamped between `min_wage` and
 * `max_wage` (riel). `reduces_taxable`: the staff share comes off before
 * Tax on Salary is worked out.
 */
#[Fillable(['code', 'name', 'employee_rate', 'employer_rate', 'min_wage', 'max_wage', 'reduces_taxable', 'is_active'])]
class SocialSecurityScheme extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $attributes = [
        'employee_rate' => 0,
        'employer_rate' => 0,
        'min_wage' => 0,
        'reduces_taxable' => true,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'employee_rate' => 'float',
            'employer_rate' => 'float',
            'min_wage' => 'float',
            'max_wage' => 'float',
            'reduces_taxable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function auditModule(): string
    {
        return 'Payroll';
    }
}
