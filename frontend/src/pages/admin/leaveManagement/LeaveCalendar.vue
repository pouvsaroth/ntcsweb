<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { leaveReportsService, leaveTypesService, type CalendarLeave, type LeaveCalendar, type LeaveType } from '@/services/leaveManagement'
import { ApiRequestError } from '@/types/api'

/**
 * HRM > Leave Management > Leave calendar — who is off on each day of a
 * month (approved leave, and pending when ticked), with holidays. A month
 * grid from `sm` up; on a phone, a list of just the days with someone off.
 */
const { t, locale } = useI18n()

const today = new Date()
const month = ref(`${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`)
const typeFilter = ref('')
const includePending = ref(true)

const leaveTypes = ref<LeaveType[]>([])
const typeOptions = computed(() => [
  { value: '', label: t('admin.leaveManagement.policies.allTypes') },
  ...leaveTypes.value.map((type) => ({ value: String(type.id), label: type.name })),
])

const calendar = ref<LeaveCalendar | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    calendar.value = await leaveReportsService.calendar(month.value, {
      leaveTypeId: typeFilter.value ? Number(typeFilter.value) : null,
      includePending: includePending.value,
    })
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.leaveManagement.saveFailed')
  } finally {
    loading.value = false
  }
}

watch([month, typeFilter, includePending], () => void load())

function shiftMonth(by: number) {
  const [y, m] = month.value.split('-').map(Number) as [number, number]
  const next = new Date(y, m - 1 + by, 1)
  month.value = `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}`
}

const monthLabel = computed(() => {
  const [y, m] = month.value.split('-').map(Number) as [number, number]
  return new Date(y, m - 1, 1).toLocaleDateString(locale.value, { month: 'long', year: 'numeric' })
})

/** Monday-first weekday names in the current language. */
const weekdays = computed(() => Array.from({ length: 7 }, (_, i) => new Date(2024, 0, 1 + i).toLocaleDateString(locale.value, { weekday: 'short' })))

interface Day {
  date: string
  day: number
  inMonth: boolean
  isToday: boolean
  holiday: string | null
  leaves: CalendarLeave[]
}

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`

/** Whole weeks, Monday to Sunday, covering the month. */
const days = computed<Day[]>(() => {
  const [y, m] = month.value.split('-').map(Number) as [number, number]
  const first = new Date(y, m - 1, 1)
  const start = new Date(first)
  start.setDate(1 - ((first.getDay() + 6) % 7))
  const last = new Date(y, m, 0)
  const end = new Date(last)
  end.setDate(last.getDate() + (6 - ((last.getDay() + 6) % 7)))
  const todayIso = iso(today)

  const result: Day[] = []
  for (const d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
    const date = iso(d)
    result.push({
      date,
      day: d.getDate(),
      inMonth: d.getMonth() === m - 1,
      isToday: date === todayIso,
      holiday: calendar.value?.holidays.find((h) => h.start_date <= date && h.end_date >= date)?.name ?? null,
      leaves: (calendar.value?.leaves ?? []).filter((leave) => leave.from_date <= date && leave.to_date >= date),
    })
  }
  return result
})

const busyDays = computed(() => days.value.filter((day) => day.inMonth && (day.leaves.length > 0 || day.holiday)))

function chipLabel(leave: CalendarLeave): string {
  const half = leave.day_part === 'morning' ? ` (${t('leaveRequest.dayMorning')})` : leave.day_part === 'afternoon' ? ` (${t('leaveRequest.dayAfternoon')})` : ''
  return `${leave.staff?.name ?? '—'}${half}`
}

function chipTitle(leave: CalendarLeave): string {
  return [leave.staff?.name, leave.leave_type?.name, leave.status === 'pending' ? t('admin.leaveRequests.statusPending') : null].filter(Boolean).join(' · ')
}

const MAX_CHIPS = 3

onMounted(async () => {
  void load()
  leaveTypes.value = await leaveTypesService.listAll().catch(() => [])
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <div class="flex items-center gap-1">
        <BaseButton variant="outline" size="sm" :aria-label="t('admin.leaveManagement.calendar.previous')" @click="shiftMonth(-1)">‹</BaseButton>
        <p class="min-w-40 text-center text-base font-semibold text-neutral-900">{{ monthLabel }}</p>
        <BaseButton variant="outline" size="sm" :aria-label="t('admin.leaveManagement.calendar.next')" @click="shiftMonth(1)">›</BaseButton>
      </div>
      <BaseSelect class="w-full sm:w-48" :model-value="typeFilter" :options="typeOptions" @update:model-value="typeFilter = $event" />
      <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
        <input v-model="includePending" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
        {{ t('admin.leaveManagement.calendar.includePending') }}
      </label>
      <div v-if="loading" class="ml-auto"><BaseSpinner /></div>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <!-- Phone: just the days with someone off, or a holiday -->
    <div class="sm:hidden">
      <p v-if="!loading && busyDays.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.leaveManagement.calendar.empty') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="day in busyDays" :key="day.date" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <p class="text-sm font-semibold text-neutral-800">
            {{ new Date(day.date + 'T00:00:00').toLocaleDateString(locale, { weekday: 'long', day: 'numeric', month: 'short' }) }}
          </p>
          <p v-if="day.holiday" class="mt-1 text-xs font-medium text-danger-600">{{ day.holiday }}</p>
          <ul class="mt-1 space-y-1">
            <li v-for="leave in day.leaves" :key="leave.id" class="flex items-center gap-2 text-sm text-neutral-700">
              <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: leave.leave_type?.color ?? '#9ca3af' }" />
              <span class="truncate">{{ chipLabel(leave) }}</span>
              <span class="shrink-0 text-xs text-neutral-500">{{ leave.leave_type?.name }}</span>
              <span v-if="leave.status === 'pending'" class="shrink-0 text-xs text-amber-700">· {{ t('admin.leaveRequests.statusPending') }}</span>
            </li>
          </ul>
        </div>
      </div>
    </div>

    <!-- Month grid from sm up -->
    <div class="hidden overflow-hidden rounded-[--radius-card] border border-neutral-200 bg-white sm:block">
      <div class="grid grid-cols-7 border-b border-neutral-200 bg-neutral-50 text-xs font-medium text-neutral-500">
        <div v-for="name in weekdays" :key="name" class="px-2 py-2 text-center">{{ name }}</div>
      </div>
      <div class="grid grid-cols-7">
        <div
          v-for="day in days"
          :key="day.date"
          class="min-h-24 border-b border-r border-neutral-100 p-1.5 [&:nth-child(7n)]:border-r-0"
          :class="[day.inMonth ? 'bg-white' : 'bg-neutral-50', day.holiday ? 'bg-red-50' : '']"
        >
          <div class="flex items-start justify-between gap-1">
            <span
              class="inline-flex h-6 w-6 items-center justify-center rounded-full text-xs"
              :class="day.isToday ? 'bg-primary-600 font-semibold text-white' : day.inMonth ? 'text-neutral-700' : 'text-neutral-300'"
            >
              {{ day.day }}
            </span>
            <span v-if="day.holiday" class="truncate text-[11px] font-medium text-danger-600" :title="day.holiday">{{ day.holiday }}</span>
          </div>
          <div class="mt-1 space-y-0.5">
            <p
              v-for="leave in day.leaves.slice(0, MAX_CHIPS)"
              :key="leave.id"
              :title="chipTitle(leave)"
              class="truncate rounded px-1.5 py-0.5 text-[11px] font-medium text-white"
              :class="leave.status === 'pending' ? 'opacity-60 ring-1 ring-inset ring-white/70' : ''"
              :style="{ backgroundColor: leave.leave_type?.color ?? '#6b7280' }"
            >
              {{ chipLabel(leave) }}
            </p>
            <p v-if="day.leaves.length > MAX_CHIPS" class="px-1 text-[11px] text-neutral-500" :title="day.leaves.slice(MAX_CHIPS).map(chipTitle).join('\n')">
              {{ t('admin.leaveManagement.calendar.more', { count: day.leaves.length - MAX_CHIPS }) }}
            </p>
          </div>
        </div>
      </div>
    </div>
    <p class="mt-2 hidden text-xs text-neutral-500 sm:block">{{ t('admin.leaveManagement.calendar.legend') }}</p>
  </div>
</template>
