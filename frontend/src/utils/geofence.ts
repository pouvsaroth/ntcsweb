import type { Geofence } from '@/services/timeAttendance'

/**
 * The same sums as the server's GeofenceService, so the check-in page can
 * say "you are at Main campus" / "350 m from Main campus" before anyone
 * presses — the server still decides.
 */
const EARTH_RADIUS_M = 6371008.8
const rad = (deg: number) => (deg * Math.PI) / 180

export function haversine(lat1: number, lng1: number, lat2: number, lng2: number): number {
  const dLat = rad(lat2 - lat1)
  const dLng = rad(lng2 - lng1)
  const a = Math.sin(dLat / 2) ** 2 + Math.cos(rad(lat1)) * Math.cos(rad(lat2)) * Math.sin(dLng / 2) ** 2
  return 2 * EARTH_RADIUS_M * Math.asin(Math.min(1, Math.sqrt(a)))
}

/** Metres from the point to the zone's edge — 0 inside. */
export function distanceOutside(zone: Geofence, lat: number, lng: number): number {
  if (zone.shape === 'circle') return Math.max(0, haversine(lat, lng, zone.latitude, zone.longitude) - (zone.radius_m ?? 0))

  const points = (zone.points ?? []).map(([pLat, pLng]) => [rad(pLng - lng) * EARTH_RADIUS_M * Math.cos(rad(lat)), rad(pLat - lat) * EARTH_RADIUS_M] as const)
  if (points.length < 3) return Infinity
  let inside = false
  let nearest = Infinity
  for (let i = 0, j = points.length - 1; i < points.length; j = i++) {
    const [xi, yi] = points[i]!
    const [xj, yj] = points[j]!
    if (yi > 0 !== yj > 0 && 0 < ((xj - xi) * (0 - yi)) / (yj - yi) + xi) inside = !inside
    const dx = xj - xi
    const dy = yj - yi
    const lengthSquared = dx * dx + dy * dy
    const t = lengthSquared > 0 ? Math.max(0, Math.min(1, -(xi * dx + yi * dy) / lengthSquared)) : 0
    nearest = Math.min(nearest, Math.hypot(xi + t * dx, yi + t * dy))
  }
  return inside ? 0 : nearest
}

export interface ZoneCheck {
  /** The zone they are in (within the GPS allowance), else null. */
  inside: Geofence | null
  nearest: Geofence | null
  /** Metres to the nearest zone's edge (0 inside). */
  distance: number
}

export function checkZones(zones: Geofence[], lat: number, lng: number, accuracy: number | null, maxTolerance: number): ZoneCheck {
  const tolerance = Math.min(maxTolerance, Math.max(0, accuracy ?? 0))
  let nearest: Geofence | null = null
  let distance = Infinity
  for (const zone of zones) {
    const d = distanceOutside(zone, lat, lng)
    if (d <= tolerance) return { inside: zone, nearest: zone, distance: 0 }
    if (d < distance) [nearest, distance] = [zone, d]
  }
  return { inside: null, nearest, distance }
}

/** 234 → "234 m", 1520 → "1.5 km". */
export function formatDistance(metres: number): string {
  return metres >= 1000 ? `${(metres / 1000).toFixed(1)} km` : `${Math.round(metres)} m`
}
