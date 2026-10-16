<?php

declare(strict_types=1);

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One move of a school's status. Written by SchoolStatus only. */
class SchoolStatusChange extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'bo_school_status_history';

    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'staff_id');
    }
}
