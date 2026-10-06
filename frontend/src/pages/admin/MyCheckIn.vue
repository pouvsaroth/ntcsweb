<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import CorrectionFormModal from '@/components/admin/CorrectionFormModal.vue'
import {
  clock,
  currentLocation,
  DAY_STYLE,
  myCorrectionsService,
  myOvertimeService,
  myStaffAttendanceService,
  STATUS_VARIANT,
  thisMonth,
  type AttendanceCorrection,
  type MyToday,
  type OvertimeRequest,
  type SheetDay,
  type SheetTotals,
} from '@/services/staffAttendance'
import { formatMinutes } from '@/services/timeAttendance'
import { ApiRequestError } from '@/types/api'

/**
 * A staff member's own Check in / Check out (HRM) — one big button, the
 * time taken by the server, the phone's location if it allows — and their
 * month below. Built phone-first: this is what staff open on arrival.
 */
const { t, locale } = useI18n()

const today = ref<MyToday | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const busy = ref(false)
const done = ref<string | null>(null)
const notStaff = ref(false)

const month = ref(thisMonth())
const days = ref<SheetDay[]>([])
const totals = ref<SheetTotals | null>(null)

// A live clock.
const now = ref(new Date())
let timer: ReturnType<typeof setInterval> | undefined
const timeText = computed(() => now.value.toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit', second: '2-digit' }))
const dateText = computed(() => now.value.toLocaleDateString(locale.value, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }))

async function loadToday() {
  try {
    today.value = await myStaffAttendanceService.today()
  } catch (e) {
    if (e instanceof ApiRequestError && e.status === 403) notStaff.value = true
    else error.value = e instanceof ApiRequestError ? e.message : t('admin.timeAttendance.my.loadFailed')
  }
}

// --- Overtime claims ------------------------------------------------------------------

const claims = ref<OvertimeRequest[]>([])
const claimByDate = computed(() => {
  const map = new Map<string, OvertimeRequest>()
  for (const c of claims.value) if (c.status !== 'rejected' || !map.has(c.date)) map.set(c.date, c)
  return map
})
const claimVariant: Record<OvertimeRequest['status'], 'warning' | 'success' | 'danger'> = { pending: 'warning', approved: 'success', rejected: 'danger' }

const claimOpen = ref(false)
const claimForm = reactive({ date: '', minutes: '', reason: '' })
const claimErrors = ref<Record<string, string[]>>({})
const claiming = ref(false)

function openClaim(day: SheetDay) {
  claimForm.date = day.date
  claimForm.minutes = String(day.overtime_minutes ?? '')
  claimForm.reason = ''
  claimErrors.value = {}
  claimOpen.value = true
}

async function submitClaim() {
  claiming.value = true
  claimErrors.value = {}
  try {
    await myOvertimeService.create({ date: claimForm.date, minutes: Number(claimForm.minutes), reason: claimForm.reason })
    claimOpen.value = false
    claims.value = await myOvertimeService.list().catch(() => claims.value)
  } catch (e) {
    claimErrors.value = e instanceof ApiRequestError && e.errors ? e.errors : { reason: [t('admin.timeAttendance.saveFailed')] }
  } finally {
    claiming.value = false
  }
}

// --- Corrections ------------------------------------------------------------------------

const corrections = ref<AttendanceCorrection[]>([])
const correctionByDate = computed(() => {
  const map = new Map<string, AttendanceCorrection>()
  for (const c of corrections.value) if (c.status === 'pending' || !map.has(c.date)) map.set(c.date, c)
  return map
})
const correctionOpen = ref(false)
const correctionPreset = ref<{ date: string; checkIn?: string | null; checkOut?: string | null } | null>(null)

function openCorrection(day: SheetDay) {
  correctionPreset.value = { date: day.date, checkIn: day.check_in_at, checkOut: day.check_out_at }
  correctionOpen.value = true
}

async function onCorrectionSaved() {
  corrections.value = await myCorrectionsService.list().catch(() => corrections.value)
  done.value = t('admin.timeAttendance.corrections.sent')
}

async function loadMonth() {
  if (notStaff.value) return
  ;[claims.value, corrections.value] = await Promise.all([myOvertimeService.list().catch(() => []), myCorrectionsService.list().catch(() => [])])
  const result = await myStaffAttendanceService.month(month.value).catch(() => null)
  days.value = (result?.days ?? []).filter((d) => d.status !== 'none' && d.status !== 'upcoming').reverse()
  totals.value = result?.totals ?? null
}

async function punch(kind: 'in' | 'out') {
  busy.value = true
  error.value = null
  done.value = null
  try {
    const location = await currentLocation()
    const record = kind === 'in' ? await myStaffAttendanceService.checkIn(location) : await myStaffAttendanceService.checkOut(location)
    done.value = t(kind === 'in' ? 'admin.timeAttendance.my.checkedIn' : 'admin.timeAttendance.my.checkedOut', {
      time: clock(kind === 'in' ? record.check_in_at : record.check_out_at),
    })
    await Promise.all([loadToday(), loadMonth()])
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.timeAttendance.my.failed')
  } finally {
    busy.value = false
  }
}

watch(month, () => void loadMonth())

onMounted(async () => {
  timer = setInterval(() => (now.value = new Date()), 1000)
  await loadToday()
  loading.value = false
  void loadMonth()
})

onBeforeUnmount(() => clearInterval(timer))
</script>

<template>
  <div class="mx-auto max-w-xl">
    <h1 class="mb-4 text-xl font-semibold text-neutral-900">{{ t('admin.timeAttendance.my.title') }}</h1>

    <BaseSpinner v-if="loading" class="mx-auto mt-8" />
    <BaseAlert v-else-if="notStaff" variant="info">{{ t('admin.timeAttendance.my.notStaff') }}</BaseAlert>

    <template v-else>
      <section class="rounded-2xl border border-neutral-200 bg-white p-6 text-center shadow-[--shadow-card]">
        <p class="text-4xl font-bold tabular-nums text-neutral-900">{{ timeText }}</p>
        <p class="mt-1 text-sm text-neutral-500">{{ dateText }}</p>
        <p v-if="today?.shift" class="mt-3 text-sm text-neutral-700">
          {{ t('admin.timeAttendance.my.todayShift', { name: today.shift.name, from: today.shift.start_time, to: today.shift.end_time }) }}
        </p>
        <p v-else class="mt-3 text-sm text-neutral-500">{{ t('admin.timeAttendance.my.noShift') }}</p>

        <BaseAlert v-if="error" variant="danger" class="mt-4 text-left">{{ error }}</BaseAlert>
        <BaseAlert v-if="done" variant="success" class="mt-4 text-left">{{ done }}</BaseAlert>

        <div class="mt-5">
          <BaseButton v-if="today?.can_check_in" size="lg" class="w-full" :loading="busy" @click="punch('in')">{{ t('admin.timeAttendance.my.checkIn') }}</BaseButton>
          <BaseButton v-else-if="today?.can_check_out" size="lg" variant="danger" class="w-full" :loading="busy" @click="punch('out')">
            {{ t('admin.timeAttendance.my.checkOut') }}
          </BaseButton>
          <p v-else class="text-sm font-medium text-success-600">{{ t('admin.timeAttendance.my.allDone') }}</p>
        </div>

        <dl v-if="today?.record" class="mt-5 grid grid-cols-2 gap-3 text-sm">
          <div class="rounded-lg bg-neutral-50 p-3">
            <dt class="text-neutral-500">{{ t('admin.timeAttendance.checkIn') }}</dt>
            <dd class="text-lg font-semibold text-neutral-900">{{ clock(today.record.check_in_at) }}</dd>
            <dd v-if="today.record.late_minutes" class="text-xs text-amber-700">{{ t('admin.timeAttendance.lateBy', { minutes: today.record.late_minutes }) }}</dd>
          </div>
          <div class="rounded-lg bg-neutral-50 p-3">
            <dt class="text-neutral-500">{{ t('admin.timeAttendance.checkOut') }}</dt>
            <dd class="text-lg font-semibold text-neutral-900">{{ clock(today.record.check_out_at) }}</dd>
            <dd v-if="today.record.early_leave_minutes" class="text-xs text-orange-700">{{ t('admin.timeAttendance.earlyBy', { minutes: today.record.early_leave_minutes }) }}</dd>
          </div>
        </dl>
        <p class="mt-3 text-xs text-neutral-400">{{ t('admin.timeAttendance.my.locationHint') }}</p>
      </section>

      <section class="mt-6">
        <div class="mb-2 flex items-center justify-between gap-3">
          <h2 class="text-sm font-semibold text-neutral-800">{{ t('admin.timeAttendance.my.myMonth') }}</h2>
          <BaseInput v-model="month" type="month" class="w-40" />
        </div>
        <div v-if="totals" class="mb-3 flex flex-wrap gap-2 text-xs">
          <BaseBadge variant="success">{{ t('admin.timeAttendance.statuses.present') }} {{ totals.present }}</BaseBadge>
          <BaseBadge variant="warning">{{ t('admin.timeAttendance.statuses.late') }} {{ totals.late }}</BaseBadge>
          <BaseBadge variant="warning">{{ t('admin.timeAttendance.statuses.early_leave') }} {{ totals.early_leave }}</BaseBadge>
          <BaseBadge variant="danger">{{ t('admin.timeAttendance.statuses.absent') }} {{ totals.absent }}</BaseBadge>
          <BaseBadge variant="primary">{{ t('admin.timeAttendance.statuses.leave') }} {{ totals.leave }}</BaseBadge>
        </div>
        <ul class="divide-y divide-neutral-100 rounded-[--radius-card] border border-neutral-200 bg-white">
          <li v-for="day in days" :key="day.date" class="flex items-center gap-3 px-3 py-2">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded text-xs font-bold" :class="DAY_STYLE[day.status].cls">{{ DAY_STYLE[day.status].short }}</span>
            <div class="min-w-0 flex-1">
              <p class="text-sm font-medium text-neutral-800">{{ new Date(`${day.date}T00:00`).toLocaleDateString(locale, { weekday: 'short', day: 'numeric', month: 'short' }) }}</p>
              <p class="text-xs text-neutral-500">
                <template v-if="day.check_in_at">{{ clock(day.check_in_at) }} – {{ clock(day.check_out_at) }}<template v-if="day.worked_minutes"> · {{ formatMinutes(day.worked_minutes) }}</template></template>
                <template v-else>{{ day.shift?.name ?? '' }}</template>
              </p>
            </div>
            <div class="flex shrink-0 flex-col items-end gap-1">
              <BaseBadge :variant="STATUS_VARIANT[day.status]">{{ t(`admin.timeAttendance.statuses.${day.status}`) }}</BaseBadge>
              <template v-if="day.overtime_minutes">
                <BaseBadge v-if="claimByDate.get(day.date)" :variant="claimVariant[claimByDate.get(day.date)!.status]">
                  +{{ formatMinutes(claimByDate.get(day.date)!.minutes) }} · {{ t(`admin.timeAttendance.overtime.claim.${claimByDate.get(day.date)!.status}`) }}
                </BaseBadge>
                <button v-else type="button" class="text-xs font-medium text-primary-700 hover:underline" @click="openClaim(day)">
                  {{ t('admin.timeAttendance.my.claimOvertime', { time: formatMinutes(day.overtime_minutes) }) }}
                </button>
              </template>
              <BaseBadge v-if="correctionByDate.get(day.date)" :variant="correctionByDate.get(day.date)!.status === 'approved' ? 'success' : correctionByDate.get(day.date)!.status === 'pending' ? 'warning' : 'danger'">
                {{ t('admin.timeAttendance.corrections.badge') }} · {{ t(`admin.timeAttendance.overtime.claim.${correctionByDate.get(day.date)!.status}`) }}
              </BaseBadge>
              <button
                v-else-if="['absent', 'incomplete', 'late', 'early_leave'].includes(day.status)"
                type="button"
                class="text-xs font-medium text-neutral-500 hover:text-primary-700 hover:underline"
                @click="openCorrection(day)"
              >
                {{ t('admin.timeAttendance.corrections.askFix') }}
              </button>
            </div>
          </li>
          <li v-if="days.length === 0" class="px-3 py-6 text-center text-sm text-neutral-400">{{ t('admin.timeAttendance.my.noDays') }}</li>
        </ul>
      </section>
    </template>

    <CorrectionFormModal v-model="correctionOpen" :preset="correctionPreset" @saved="onCorrectionSaved" />

    <BaseModal v-model="claimOpen" :title="t('admin.timeAttendance.my.claimTitle')">
      <form class="space-y-4" @submit.prevent="submitClaim">
        <div class="grid grid-cols-2 gap-4">
          <BaseInput v-model="claimForm.date" type="date" disabled :label="t('admin.timeAttendance.holidays.date')" :error="claimErrors.date?.[0]" />
          <BaseInput v-model="claimForm.minutes" type="number" min="1" required :label="t('admin.timeAttendance.overtime.minutes')" :error="claimErrors.minutes?.[0]" />
        </div>
        <BaseInput v-model="claimForm.reason" required :label="t('admin.timeAttendance.overtime.reason')" :placeholder="t('admin.timeAttendance.my.claimReasonHint')" :error="claimErrors.reason?.[0]" />
        <p class="text-xs text-neutral-500">{{ t('admin.timeAttendance.overtime.goesToApprovals') }}</p>
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="claimOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="claiming" @click="submitClaim">{{ t('admin.timeAttendance.overtime.submit') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
