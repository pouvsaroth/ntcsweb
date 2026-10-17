<?php

declare(strict_types=1);

namespace App\Platform\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One backup run of one database. Written by BackupRunner. */
class Backup extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    /** A whole database (pg_dump). */
    public const FORMAT_DUMP = 'dump';

    /** One school's own rows (SchoolExporter) — a shared school's backup. */
    public const FORMAT_SCHOOL = 'school';

    protected $table = 'bo_backups';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'duration_ms' => 'integer',
            'copied_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    public function scopeGood(Builder $query): void
    {
        $query->where('status', self::STATUS_OK);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'staff_id');
    }
}
