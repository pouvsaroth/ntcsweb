<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-owned. A place staff may check in / out from (HRM > Attendance &
 * Time > Geofences): a circle — centre + radius_m — or a polygon — points,
 * [[lat, lng], ...], with latitude/longitude its middle. While any zone is
 * active, the app's check-in needs a location inside one (GeofenceService).
 */
#[Fillable(['name', 'shape', 'latitude', 'longitude', 'radius_m', 'points', 'is_active', 'note'])]
class Geofence extends Model
{
    use Auditable;

    public const CIRCLE = 'circle';

    public const POLYGON = 'polygon';

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'radius_m' => 'integer',
            'points' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function auditModule(): string
    {
        return 'Attendance';
    }
}
