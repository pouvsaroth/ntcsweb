<?php

declare(strict_types=1);

namespace App\Platform\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One school in one Migrate / Create-database run. */
class MigrationRun extends Model
{
    public const KIND_MIGRATE = 'migrate';

    public const KIND_CREATE = 'create';

    protected $table = 'bo_migration_runs';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['applied' => 'integer', 'duration_ms' => 'integer'];
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
}
