<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Tenant-owned. One interviewer (a user id) on one Interview. */
#[Fillable(['interview_id', 'user_id'])]
class InterviewInterviewer extends Model
{
    public $timestamps = false;

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return ['interview_id' => 'integer', 'user_id' => 'integer'];
    }
}
