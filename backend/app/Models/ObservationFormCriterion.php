<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Tenant-owned. One criterion of an observation form, rated 1–5 stars. */
#[Fillable(['observation_form_id', 'section', 'criterion', 'sort_order'])]
class ObservationFormCriterion extends Model
{
    protected $connection = 'tenant';

    protected $table = 'observation_form_criteria';
}
