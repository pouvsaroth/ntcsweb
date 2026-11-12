<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. One staff member's own geofence rule: `anywhere` (no zone
 * needed — drivers, field staff) or `zones` (only geofence_ids). Staff
 * without a rule may use any active zone.
 */
#[Fillable(['staff_id', 'mode', 'geofence_ids'])]
class StaffGeofenceRule extends Model
{
    use Auditable;

    public const ANYWHERE = 'anywhere';

    public const ZONES = 'zones';

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return ['geofence_ids' => 'array'];
    }

    /** @return BelongsTo<Staff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function auditModule(): string
    {
        return 'Attendance';
    }
}
