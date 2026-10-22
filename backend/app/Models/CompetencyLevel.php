<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Tenant-owned. A level in competency mode (e.g. Very good = 4 points), and whether it passes. See docs/academics/gradebook.md. */
#[Fillable(['assessment_scheme_id', 'code', 'label', 'label_km', 'points', 'is_passing', 'sort_order'])]
class CompetencyLevel extends Model
{
    protected $connection = 'tenant';

    protected function casts(): array
    {
        return ['points' => 'decimal:2', 'is_passing' => 'boolean', 'sort_order' => 'integer'];
    }
}
