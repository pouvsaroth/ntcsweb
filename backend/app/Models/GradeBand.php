<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Tenant-owned. A letter and label for results at or above `min_percent`. See docs/academics/gradebook.md. */
#[Fillable(['assessment_scheme_id', 'letter', 'label', 'label_km', 'min_percent'])]
class GradeBand extends Model
{
    protected $connection = 'tenant';

    protected function casts(): array
    {
        return ['min_percent' => 'decimal:2'];
    }
}
