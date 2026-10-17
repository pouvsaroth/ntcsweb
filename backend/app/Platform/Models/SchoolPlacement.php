<?php

declare(strict_types=1);

namespace App\Platform\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Where one school's data lives (docs/backoffice/stage-4-database-placement.md):
 *
 *   shared     rows with tenant_id in the shared database (school_share)
 *   dedicated  its own database on the main server, cloned from tn_template
 *   separate   its own database on another Postgres server, reached with the
 *              login staff pasted (password encrypted, never sent back)
 *
 * A school with no row is dedicated — every school from before placements.
 */
class SchoolPlacement extends Model
{
    public const SHARED = 'shared';

    public const DEDICATED = 'dedicated';

    public const SEPARATE = 'separate';

    public const MODES = [self::SHARED, self::DEDICATED, self::SEPARATE];

    protected $table = 'bo_school_placements';

    protected $primaryKey = 'tenant_id';

    public $incrementing = false;

    protected $guarded = [];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'password' => 'encrypted',
        ];
    }

    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    /** The placement in force: the stored row, or "dedicated" for schools from before placements. */
    public static function of(Tenant $tenant): self
    {
        return self::query()->find($tenant->getKey()) ?? new self([
            'tenant_id' => $tenant->getKey(),
            'mode' => self::DEDICATED,
            'database' => config('tenancy.database.name_prefix').$tenant->slug,
        ]);
    }

    public function isShared(): bool
    {
        return $this->mode === self::SHARED;
    }

    public function isSeparate(): bool
    {
        return $this->mode === self::SEPARATE;
    }
}
