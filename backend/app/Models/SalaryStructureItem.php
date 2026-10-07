<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. One component of a salary structure with its monthly amount
 * — in the structure's currency, or a percentage of basic salary when the
 * component is calculated that way. Saved with its structure (see
 * SalaryStructureController), so it isn't audited on its own.
 */
#[Fillable(['salary_structure_id', 'payroll_component_id', 'amount'])]
class SalaryStructureItem extends Model
{
    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'salary_structure_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayrollComponent::class, 'payroll_component_id');
    }
}
