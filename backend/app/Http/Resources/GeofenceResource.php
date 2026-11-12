<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Geofence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Geofence
 */
class GeofenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'shape' => $this->shape,
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'radius_m' => $this->radius_m,
            'points' => $this->shape === Geofence::POLYGON ? array_map(fn (array $p) => [(float) $p[0], (float) $p[1]], $this->points ?? []) : null,
            'is_active' => (bool) $this->is_active,
            'note' => $this->note,
        ];
    }
}
