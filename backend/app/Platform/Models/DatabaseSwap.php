<?php

declare(strict_types=1);

namespace App\Platform\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A restored database put in place of a school's live one (DatabaseSwapper). */
class DatabaseSwap extends Model
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    protected $table = 'bo_database_swaps';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['steps' => 'array', 'finished_at' => 'datetime'];
    }

    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'staff_id');
    }

    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class);
    }
}
