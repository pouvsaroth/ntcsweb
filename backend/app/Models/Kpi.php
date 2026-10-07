<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\KpiFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. One KPI in the library (HRM > Performance Management > KPI)
 * — "Class pass rate", "Student retention" — how it's measured, its unit
 * and target, and whether higher is better. For everyone, or one
 * department / position (offered for their reviews). A review takes its
 * own copy with its own target and weight.
 */
#[Fillable(['code', 'name', 'description', 'measurement', 'unit', 'target', 'higher_is_better', 'default_weight', 'department_id', 'position_id', 'is_active'])]
class Kpi extends Model
{
    /** @use HasFactory<KpiFactory> */
    use Auditable, HasFactory;

    protected $connection = 'tenant';

    protected $attributes = [
        'higher_is_better' => true,
        'default_weight' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'target' => 'float',
            'higher_is_better' => 'boolean',
            'default_weight' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function auditModule(): string
    {
        return 'Performance';
    }
}
