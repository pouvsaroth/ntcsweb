<?php

declare(strict_types=1);

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/** One request a support view made: method, path, status and time — never the data. */
class SupportSessionEvent extends Model
{
    public $timestamps = false;

    protected $table = 'bo_support_session_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['at' => 'datetime', 'status' => 'integer'];
    }

    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }
}
