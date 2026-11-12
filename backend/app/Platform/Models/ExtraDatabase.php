<?php

declare(strict_types=1);

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A database staff created in the back office (Data ops > Databases,
 * "Other"): empty, from the school template, a copy of another database, or
 * restored. Made in the background (CreateExtraDatabase) — `status` says how
 * it went. Listed by PlatformDatabases as kind `extra`.
 */
class ExtraDatabase extends Model
{
    public const SOURCE_EMPTY = 'empty';

    public const SOURCE_TEMPLATE = 'template';

    public const SOURCE_COPY = 'copy';

    public const SOURCE_BACKUP = 'backup';

    public const SOURCE_UPLOAD = 'upload';

    /** A school's live database, kept under a new name after a restored one was put in its place. */
    public const SOURCE_REPLACED = 'replaced';

    public const STATUS_CREATING = 'creating';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    /** Lowercase letters, digits and _; starts with a letter; Postgres' 63-character limit. */
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{2,62}$/';

    protected $table = 'bo_databases';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['finished_at' => 'datetime'];
    }

    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
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
