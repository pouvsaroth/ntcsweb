import { onBeforeUnmount, ref } from 'vue'

import type { DeviceLocation } from '@/services/staffAttendance'

export type LocationState = 'idle' | 'locating' | 'ok' | 'denied' | 'unavailable'

/**
 * The phone's position, kept up to date while the page is open (for the
 * check-in place status). `start()` asks for it — the browser shows its
 * permission prompt the first time; stops on its own when the page closes.
 */
export function useLiveLocation() {
  const location = ref<DeviceLocation | null>(null)
  const state = ref<LocationState>('idle')
  let watchId: number | null = null

  function stop() {
    if (watchId !== null) navigator.geolocation.clearWatch(watchId)
    watchId = null
  }

  function start() {
    stop()
    if (!('geolocation' in navigator)) {
      state.value = 'unavailable'
      return
    }
    if (!location.value) state.value = 'locating'
    watchId = navigator.geolocation.watchPosition(
      (position) => {
        location.value = {
          latitude: position.coords.latitude,
          longitude: position.coords.longitude,
          accuracy: Number.isFinite(position.coords.accuracy) ? Math.round(position.coords.accuracy) : null,
        }
        state.value = 'ok'
      },
      (error) => {
        if (error.code === error.PERMISSION_DENIED) {
          location.value = null
          state.value = 'denied'
          stop()
        } else if (!location.value) {
          state.value = 'unavailable'
        }
      },
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 10000 },
    )
  }

  onBeforeUnmount(stop)

  return { location, state, start, stop }
}
