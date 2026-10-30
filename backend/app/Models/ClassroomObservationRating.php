<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Tenant-owned. A criterion on one observation (copied from its form) and its 1–5 rating. */
#[Fillable(['classroom_observation_id', 'section', 'criterion', 'rating', 'comment', 'sort_order'])]
class ClassroomObservationRating extends Model
{
    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
        ];
    }
}
