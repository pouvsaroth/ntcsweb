<?php

declare(strict_types=1);

namespace App\Platform\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One move of a school's data between placements (SchoolMover). */
class SchoolMove extends Model
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    protected $table = 'bo_school_moves';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            // Each holds a whole placement, a separate server's password included.
            'from_placement' => 'encrypted:array',
            'to_placement' => 'encrypted:array',
            'steps' => 'array',
            'counts' => 'array',
            'old_copy_removed_at' => 'datetime',
        ];
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

    /** A placement as a model (unsaved), from the stored array. */
    public function placement(string $which): SchoolPlacement
    {
        return new SchoolPlacement($which === 'from' ? $this->from_placement : $this->to_placement);
    }
}
