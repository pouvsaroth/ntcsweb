<?php

declare(strict_types=1);

namespace App\Services\StaffAttendance;

use App\Models\Geofence;
use App\Models\Staff;
use App\Models\StaffGeofenceRule;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Where a staff member may check in / out from the app. With no active
 * zone, anywhere. Otherwise inside one of their zones: every active zone,
 * or only their rule's zones (StaffGeofenceRule), or anywhere by their rule.
 *
 * Distances are on a flat local projection around the point — exact to
 * well under a metre over a school campus.
 */
final class GeofenceService
{
    private const EARTH_RADIUS_M = 6371008.8;

    /** Phones' GPS wanders; a reading this close outside still counts (capped by its own accuracy). */
    public const MAX_TOLERANCE_M = 30;

    /**
     * The zones this staff member must be inside, or null when they may
     * check in anywhere.
     *
     * @return Collection<int, Geofence>|null
     */
    public function zonesFor(Staff $staff): ?Collection
    {
        $active = Geofence::query()->where('is_active', true)->orderBy('name')->get();
        if ($active->isEmpty()) {
            return null;
        }

        $rule = StaffGeofenceRule::query()->where('staff_id', $staff->id)->first();
        if ($rule?->mode === StaffGeofenceRule::ANYWHERE) {
            return null;
        }
        if ($rule?->mode === StaffGeofenceRule::ZONES) {
            $own = $active->whereIn('id', array_map('intval', $rule->geofence_ids ?? []))->values();
            // Their zones were all deleted or turned off — fall back to the school's.
            if ($own->isNotEmpty()) {
                return $own;
            }
        }

        return $active;
    }

    /**
     * Refuses a check-in / out outside the staff member's zones. Returns the
     * zone it was made in (null when no zone is needed).
     */
    public function assertInside(Staff $staff, ?float $latitude, ?float $longitude, ?float $accuracy, string $field): ?Geofence
    {
        $zones = $this->zonesFor($staff);
        if ($zones === null) {
            return null;
        }

        if ($latitude === null || $longitude === null) {
            throw ValidationException::withMessages([$field => __('Turn on location to check in or out — it must be at one of the check-in places.')]);
        }

        $tolerance = min(self::MAX_TOLERANCE_M, max(0.0, $accuracy ?? 0.0));
        $nearest = null;
        $nearestDistance = INF;
        foreach ($zones as $zone) {
            $distance = $this->distanceOutside($zone, $latitude, $longitude);
            if ($distance <= $tolerance) {
                return $zone;
            }
            if ($distance < $nearestDistance) {
                [$nearest, $nearestDistance] = [$zone, $distance];
            }
        }

        throw ValidationException::withMessages([$field => __('You are :distance from :place. Check in or out only at a check-in place.', [
            'distance' => self::formatDistance($nearestDistance),
            'place' => $nearest?->name,
        ])]);
    }

    /** Metres from the point to the zone's edge — 0 inside. */
    public function distanceOutside(Geofence $zone, float $latitude, float $longitude): float
    {
        if ($zone->shape === Geofence::CIRCLE) {
            return max(0.0, self::haversine($latitude, $longitude, (float) $zone->latitude, (float) $zone->longitude) - (float) $zone->radius_m);
        }

        // Polygon corners in metres east / north of the point; the point is (0, 0).
        $points = array_map(fn (array $p) => self::project($latitude, $longitude, (float) $p[0], (float) $p[1]), $zone->points ?? []);
        $count = count($points);
        if ($count < 3) {
            return INF;
        }

        $inside = false;
        $nearest = INF;
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$xi, $yi] = $points[$i];
            [$xj, $yj] = $points[$j];
            if (($yi > 0) !== ($yj > 0) && 0 < ($xj - $xi) * (0 - $yi) / ($yj - $yi) + $xi) {
                $inside = ! $inside;
            }
            $nearest = min($nearest, self::distanceToSegment($xi, $yi, $xj, $yj));
        }

        return $inside ? 0.0 : $nearest;
    }

    public static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($a)));
    }

    /** The middle of a polygon's corners (an average — fine for the map pin and "nearest"). @param list<array{0: float, 1: float}> $points */
    public static function middle(array $points): array
    {
        $count = max(1, count($points));

        return [array_sum(array_column($points, 0)) / $count, array_sum(array_column($points, 1)) / $count];
    }

    public static function formatDistance(float $metres): string
    {
        return $metres >= 1000 ? number_format($metres / 1000, 1).' km' : (int) round($metres).' m';
    }

    /** @return array{0: float, 1: float} metres east, north of the origin */
    private static function project(float $originLat, float $originLng, float $lat, float $lng): array
    {
        return [
            deg2rad($lng - $originLng) * self::EARTH_RADIUS_M * cos(deg2rad($originLat)),
            deg2rad($lat - $originLat) * self::EARTH_RADIUS_M,
        ];
    }

    /** From the origin to the segment (x1,y1)–(x2,y2). */
    private static function distanceToSegment(float $x1, float $y1, float $x2, float $y2): float
    {
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        $lengthSquared = $dx * $dx + $dy * $dy;
        $t = $lengthSquared > 0 ? max(0.0, min(1.0, -($x1 * $dx + $y1 * $dy) / $lengthSquared)) : 0.0;

        return hypot($x1 + $t * $dx, $y1 + $t * $dy);
    }
}
