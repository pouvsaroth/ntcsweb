<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\SalaryStructureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned. A reusable pay package (HRM > Payroll > Salary structure) —
 * e.g. "Full-time teacher" — of components with their monthly amounts, in
 * one currency. A staff member's salary (StaffSalary) may follow one; their
 * own assignments (StaffPayComponent) add to it or replace one of its items.
 */
#[Fillable(['code', 'name', 'currency', 'description', 'is_active'])]
class SalaryStructure extends Model
{
    /** @use HasFactory<SalaryStructureFactory> */
    use Auditable, HasFactory;

    protected $connection = 'tenant';

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalaryStructureItem::class);
    }

    public function salaries(): HasMany
    {
        return $this->hasMany(StaffSalary::class);
    }

    public function auditModule(): string
    {
        return 'Payroll';
    }
}
