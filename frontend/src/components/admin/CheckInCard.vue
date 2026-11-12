<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { useLiveLocation } from '@/composables/useLiveLocation'
import { clock, currentLocation, type MyToday, myStaffAttendanceService } from '@/services/staffAttendance'
import { formatMinutes } from '@/services/timeAttendance'
import { ApiRequestError } from '@/types/api'
import { checkZones, formatDistance } from '@/utils/geofence'

/**
 * Today's Check in / Check out, for My check-in and the Dashboard: a live
 * clock, where the phone is against the school's check-in places (when it
 * has any), one big round button — its ring fills as the shift's hours are
 * worked — and today's shift, check-in and check-out. Asks to confirm, then
 * the server records the time and checks the place again.
 */
const props = withDefaults(defineProps<{ today: MyToday; compact?: boolean }>(), { compact: false })
const emit = defineEmits<{ changed: [] }>()

const { t, locale } = useI18n()

// --- Clock -----------------------------------------------------------------------------

const now = ref(new Date())
let timer: ReturnType<typeof setInterval> | undefined
const timeText = computed(() => now.value.toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' }))
const secondsText = computed(() => String(now.value.getSeconds()).padStart(2, '0'))
const dateText = computed(() => now.value.toLocaleDateString(locale.value, { weekday: 'long', day: 'numeric', month: 'long' }))

// --- Where the phone is ----------------------------------------------------------------------

const { location, state: locationState, start: startLocation } = useLiveLocation()
const required = computed(() => props.today.geofence?.required ?? false)
const zoneCheck = computed(() => {
  if (!required.value || !location.value) return null
  return checkZones(props.today.geofence.zones, location.value.latitude, location.value.longitude, location.value.accuracy, props.today.geofence.max_tolerance_m)
})
/** Something to press today. */
const canPunch = computed(() => props.today.can_check_in || props.today.can_check_out)
/** The place rule lets them press now (the server checks again). */
const placeOk = computed(() => !required.value || zoneCheck.value?.inside != null)

type Place = { tone: 'ok' | 'bad' | 'wait' | 'muted'; title: string; detail?: string }
const place = computed<Place | null>(() => {
  if (!canPunch.value) return null
  if (!required.value) return { tone: 'muted', title: t('admin.timeAttendance.my.locationHint') }
  if (locationState.value === 'denied') return { tone: 'bad', title: t('admin.timeAttendance.my.locationOff'), detail: t('admin.timeAttendance.my.locationOffHelp') }
  if (locationState.value === 'unavailable' && !location.value) return { tone: 'bad', title: t('admin.timeAttendance.my.locationUnavailable'), detail: t('admin.timeAttendance.my.locationOffHelp') }
  if (!zoneCheck.value) return { tone: 'wait', title: t('admin.timeAttendance.my.locating') }
  const accuracy = location.value?.accuracy ? ` · ±${location.value.accuracy} m` : ''
  if (zoneCheck.value.inside) return { tone: 'ok', title: t('admin.timeAttendance.my.atPlace', { place: zoneCheck.value.inside.name }), detail: t('admin.timeAttendance.my.insidePlace') + accuracy }
  return {
    tone: 'bad',
    title: t('admin.timeAttendance.my.awayFrom', { distance: formatDistance(zoneCheck.value.distance), place: zoneCheck.value.nearest?.name ?? '' }),
    detail: t('admin.timeAttendance.my.moveInside') + accuracy,
  }
})

const PLACE_STYLE = {
  ok: 'border-green-200 bg-green-50 text-green-800',
  bad: 'border-red-200 bg-red-50 text-red-800',
  wait: 'border-neutral-200 bg-neutral-50 text-neutral-600',
  muted: 'border-transparent bg-transparent text-neutral-400',
} as const

// --- Today's hours -------------------------------------------------------------------------

function minutesOf(hhmm: string): number {
  const [h, m] = hhmm.split(':').map(Number)
  return (h ?? 0) * 60 + (m ?? 0)
}

const shiftMinutes = computed(() => {
  const shift = props.today.shift
  if (!shift) return 0
  const length = minutesOf(shift.end_time) - minutesOf(shift.start_time)
  return length > 0 ? length : length + 24 * 60
})

/** Worked so far (to the check-out, or to now while checked in). */
const workedMinutes = computed(() => {
  const record = props.today.record
  if (!record?.check_in_at) return 0
  const end = record.check_out_at ? new Date(record.check_out_at) : now.value
  return Math.max(0, Math.floor((end.getTime() - new Date(record.check_in_at).getTime()) / 60000))
})

const progress = computed(() => (shiftMinutes.value > 0 ? Math.min(1, workedMinutes.value / shiftMinutes.value) : props.today.record?.check_in_at ? 1 : 0))

// The ring around the button.
const RING = 54
const circumference = 2 * Math.PI * RING
const ringOffset = computed(() => circumference * (1 - progress.value))

const mode = computed<'in' | 'out' | 'done'>(() => (props.today.can_check_in ? 'in' : props.today.can_check_out ? 'out' : 'done'))
const BUTTON_STYLE = {
  in: 'bg-gradient-to-br from-primary-500 to-primary-700 text-white shadow-primary-600/30',
  out: 'bg-gradient-to-br from-rose-500 to-red-700 text-white shadow-red-600/30',
  done: 'bg-gradient-to-br from-green-500 to-green-700 text-white shadow-green-600/30',
} as const
const RING_COLOR = { in: 'text-primary-500', out: 'text-red-500', done: 'text-green-500' } as const

// --- Pressing --------------------------------------------------------------------------------

const confirmOpen = ref(false)
const confirmTime = ref('')
const busy = ref(false)
const message = ref<{ ok: boolean; text: string } | null>(null)

function ask() {
  if (mode.value === 'done' || busy.value) return
  if (!placeOk.value) {
    if (locationState.value === 'denied' || locationState.value === 'unavailable') startLocation()
    return
  }
  confirmTime.value = clock(new Date().toISOString())
  confirmOpen.value = true
}

async function punch() {
  const kind = mode.value
  confirmOpen.value = false
  if (kind === 'done') return
  busy.value = true
  message.value = null
  try {
    const where = location.value ?? (await currentLocation())
    const record = kind === 'in' ? await myStaffAttendanceService.checkIn(where) : await myStaffAttendanceService.checkOut(where)
    message.value = {
      ok: true,
      text: t(kind === 'in' ? 'admin.timeAttendance.my.checkedIn' : 'admin.timeAttendance.my.checkedOut', { time: clock(kind === 'in' ? record.check_in_at : record.check_out_at) }),
    }
    emit('changed')
  } catch (e) {
    message.value = { ok: false, text: e instanceof ApiRequestError ? e.message : t('admin.timeAttendance.my.failed') }
  } finally {
    busy.value = false
  }
}

// Ask the phone where it is only when it matters (places are on and there is something to press) —
// otherwise the location is read once, when pressing.
watch(
  () => required.value && canPunch.value,
  (needed) => {
    if (needed && locationState.value === 'idle') startLocation()
  },
  { immediate: true },
)

onMounted(() => {
  timer = setInterval(() => (now.value = new Date()), 1000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>

<template>
  <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-[--shadow-card]">
    <div :class="compact ? 'grid gap-4 p-4 sm:grid-cols-[auto_1fr] sm:items-center sm:gap-6 sm:p-5' : 'p-5 sm:p-6'">
      <!-- Clock, place, button -->
      <div class="flex flex-col items-center text-center">
        <p v-if="!compact" class="text-sm font-medium text-neutral-500">{{ dateText }}</p>
        <p v-if="!compact" class="mt-1 text-5xl font-bold tabular-nums tracking-tight text-neutral-900">
          {{ timeText }}<span class="ml-1 align-top text-lg font-semibold text-neutral-400">{{ secondsText }}</span>
        </p>

        <button
          type="button"
          class="group relative mt-5 flex items-center justify-center rounded-full outline-none focus-visible:ring-4 focus-visible:ring-primary-200"
          :class="[compact ? 'h-36 w-36' : 'h-52 w-52', compact ? 'mt-0' : '']"
          :disabled="busy || mode === 'done'"
          :aria-label="mode === 'in' ? t('admin.timeAttendance.my.checkIn') : mode === 'out' ? t('admin.timeAttendance.my.checkOut') : t('admin.timeAttendance.my.allDone')"
          @click="ask"
        >
          <!-- Ring: how much of the shift is worked -->
          <svg class="absolute inset-0 h-full w-full -rotate-90" viewBox="0 0 120 120" aria-hidden="true">
            <circle cx="60" cy="60" :r="RING" fill="none" stroke-width="6" class="stroke-neutral-100" />
            <circle
              cx="60"
              cy="60"
              :r="RING"
              fill="none"
              stroke-width="6"
              stroke-linecap="round"
              stroke="currentColor"
              :class="RING_COLOR[mode]"
              :stroke-dasharray="circumference"
              :stroke-dashoffset="ringOffset"
              style="transition: stroke-dashoffset 0.6s ease"
            />
          </svg>
          <span
            class="relative flex flex-col items-center justify-center rounded-full shadow-lg transition"
            :class="[
              compact ? 'h-28 w-28' : 'h-40 w-40',
              BUTTON_STYLE[mode],
              placeOk || mode === 'done' ? 'group-hover:scale-[1.03] group-active:scale-95' : 'opacity-40 grayscale',
            ]"
          >
            <span v-if="mode === 'in' && placeOk && !busy" class="absolute -inset-1.5 -z-10 animate-pulse rounded-full bg-primary-400/25" />
            <svg v-if="busy" class="h-8 w-8 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
            </svg>
            <svg v-else-if="mode === 'done'" :class="compact ? 'h-9 w-9' : 'h-12 w-12'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
            </svg>
            <svg v-else :class="compact ? 'h-8 w-8' : 'h-10 w-10'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path v-if="mode === 'in'" stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l3 3m0 0l-3 3m3-3H2.25" />
              <path v-else stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" />
            </svg>
            <span class="mt-1 font-bold" :class="compact ? 'text-sm' : 'text-lg'">
              {{ mode === 'in' ? t('admin.timeAttendance.my.checkIn') : mode === 'out' ? t('admin.timeAttendance.my.checkOut') : t('admin.timeAttendance.my.doneShort') }}
            </span>
            <span v-if="compact && mode !== 'done'" class="text-xs tabular-nums opacity-90">{{ timeText }}</span>
          </span>
        </button>
      </div>

      <div :class="compact ? 'min-w-0 space-y-3' : 'mt-5 space-y-4'">
        <p v-if="compact" class="text-sm font-semibold text-neutral-900">{{ t('admin.timeAttendance.my.title') }} <span class="font-normal text-neutral-500">· {{ dateText }}</span></p>

        <!-- Where they are -->
        <div v-if="place" class="flex items-start gap-2 rounded-xl border px-3 py-2 text-left text-sm" :class="PLACE_STYLE[place.tone]">
          <svg class="mt-0.5 h-4 w-4 shrink-0" :class="place.tone === 'wait' ? 'animate-pulse' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
          </svg>
          <div class="min-w-0 flex-1">
            <p class="font-semibold">{{ place.title }}</p>
            <p v-if="place.detail" class="text-xs opacity-80">{{ place.detail }}</p>
          </div>
          <button v-if="place.tone === 'bad'" type="button" class="shrink-0 text-xs font-semibold underline" @click="startLocation">{{ t('admin.timeAttendance.my.tryAgain') }}</button>
        </div>

        <p v-if="message" class="rounded-xl px-3 py-2 text-left text-sm font-medium" :class="message.ok ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'">{{ message.text }}</p>

        <!-- Today: shift, in, out -->
        <div class="grid grid-cols-3 divide-x divide-neutral-100 rounded-xl bg-neutral-50 text-center">
          <div class="px-2 py-2.5">
            <p class="text-[11px] font-medium uppercase tracking-wide text-neutral-500">{{ t('admin.timeAttendance.my.shift') }}</p>
            <p class="text-sm font-semibold tabular-nums text-neutral-900">{{ today.shift ? `${today.shift.start_time}–${today.shift.end_time}` : '—' }}</p>
            <p class="truncate text-[11px] text-neutral-500">{{ today.shift?.name ?? t('admin.timeAttendance.my.noShift') }}</p>
          </div>
          <div class="px-2 py-2.5">
            <p class="text-[11px] font-medium uppercase tracking-wide text-neutral-500">{{ t('admin.timeAttendance.checkIn') }}</p>
            <p class="text-sm font-semibold tabular-nums" :class="today.record?.check_in_at ? 'text-neutral-900' : 'text-neutral-400'">{{ today.record?.check_in_at ? clock(today.record.check_in_at) : '--:--' }}</p>
            <p v-if="today.record?.late_minutes" class="text-[11px] font-medium text-amber-700">{{ t('admin.timeAttendance.lateBy', { minutes: today.record.late_minutes }) }}</p>
            <p v-else-if="today.record?.check_in_geofence" class="truncate text-[11px] text-neutral-500">{{ today.record.check_in_geofence }}</p>
            <p v-else-if="today.record?.check_in_at" class="text-[11px] font-medium text-green-700">{{ t('admin.timeAttendance.my.onTime') }}</p>
          </div>
          <div class="px-2 py-2.5">
            <p class="text-[11px] font-medium uppercase tracking-wide text-neutral-500">{{ t('admin.timeAttendance.checkOut') }}</p>
            <p class="text-sm font-semibold tabular-nums" :class="today.record?.check_out_at ? 'text-neutral-900' : 'text-neutral-400'">{{ today.record?.check_out_at ? clock(today.record.check_out_at) : '--:--' }}</p>
            <p v-if="today.record?.early_leave_minutes" class="text-[11px] font-medium text-orange-700">{{ t('admin.timeAttendance.earlyBy', { minutes: today.record.early_leave_minutes }) }}</p>
            <p v-else-if="today.record?.check_out_geofence" class="truncate text-[11px] text-neutral-500">{{ today.record.check_out_geofence }}</p>
          </div>
        </div>

        <!-- Hours worked against the shift -->
        <div v-if="today.record?.check_in_at">
          <div class="mb-1 flex justify-between text-xs text-neutral-600">
            <span>{{ today.record.check_out_at ? t('admin.timeAttendance.my.worked') : t('admin.timeAttendance.my.workedSoFar') }}</span>
            <span class="font-semibold tabular-nums">{{ formatMinutes(workedMinutes) }}<template v-if="shiftMinutes"> / {{ formatMinutes(shiftMinutes) }}</template></span>
          </div>
          <div class="h-2 overflow-hidden rounded-full bg-neutral-100">
            <div class="h-full rounded-full transition-all" :class="mode === 'done' ? 'bg-green-500' : 'bg-primary-500'" :style="{ width: `${Math.round(progress * 100)}%` }" />
          </div>
        </div>
        <slot />
      </div>
    </div>

    <BaseModal v-model="confirmOpen" size="sm" :title="mode === 'out' ? t('admin.timeAttendance.my.checkOut') : t('admin.timeAttendance.my.checkIn')">
      <p class="text-center text-4xl font-bold tabular-nums text-neutral-900">{{ confirmTime }}</p>
      <p class="mt-2 text-center text-sm text-neutral-600">{{ t(mode === 'out' ? 'admin.timeAttendance.my.confirmCheckOut' : 'admin.timeAttendance.my.confirmCheckIn', { time: confirmTime }) }}</p>
      <p v-if="zoneCheck?.inside" class="mt-1 text-center text-xs font-medium text-green-700">{{ t('admin.timeAttendance.my.atPlace', { place: zoneCheck.inside.name }) }}</p>
      <template #footer>
        <BaseButton variant="outline" @click="confirmOpen = false">{{ t('common.cancel') }}</BaseButton>
        <BaseButton :variant="mode === 'out' ? 'danger' : 'primary'" @click="punch">{{ t('common.confirm') }}</BaseButton>
      </template>
    </BaseModal>
  </section>
</template>
