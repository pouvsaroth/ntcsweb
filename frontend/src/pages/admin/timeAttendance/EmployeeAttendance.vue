<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import AttendanceEntryModal from '@/components/admin/AttendanceEntryModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { departmentsService, type Department } from '@/services/departments'
import { staffService, type Staff } from '@/services/staff'
import { clock, DAY_STATUSES, DAY_STYLE, staffAttendanceService, thisMonth, type SheetDay, type SheetRow } from '@/services/staffAttendance'
import { formatMinutes } from '@/services/timeAttendance'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'

/**
 * HRM > Attendance & Time > Employee attendance: a month at a glance — one
 * row per staff member, one cell per day, then their totals. A phone gets a
 * card per person with the totals and the days as a strip. Someone who can
 * manage attendance clicks a day to set or fix its times.
 */
const { t, locale } = useI18n()
const auth = useAuthStore()

const canManage = computed(() => auth.can('staff-attendance.manage'))

const month = ref(thisMonth())
const departmentId = ref('')
const search = ref('')
const rows = ref<SheetRow[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

const departments = ref<Department[]>([])
const staff = ref<Staff[]>([])
const departmentOptions = computed(() => [
  { value: '', label: t('admin.timeAttendance.allDepartments') },
  ...departments.value.map((d) => ({ value: String(d.id), label: d.name })),
])

const dates = computed(() => rows.value[0]?.days.map((d) => d.date) ?? [])

async function load() {
  loading.value = true
  error.value = null
  try {
    const result = await staffAttendanceService.sheet({
      month: month.value,
      ...(departmentId.value ? { department_id: Number(departmentId.value) } : {}),
      ...(search.value.trim() ? { search: search.value.trim() } : {}),
    })
    rows.value = result.staff
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.timeAttendance.loadFailed')
  } finally {
    loading.value = false
  }
}

let searchTimer: ReturnType<typeof setTimeout> | undefined
function onSearch(value: string) {
  search.value = value
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => void load(), 350)
}

function dayNumber(date: string): string {
  return String(Number(date.slice(8)))
}

function weekday(date: string): string {
  return new Date(`${date}T00:00`).toLocaleDateString(locale.value, { weekday: 'narrow' })
}

function isWeekend(date: string): boolean {
  const day = new Date(`${date}T00:00`).getDay()
  return day === 0 || day === 6
}

function cellTitle(row: SheetRow, day: SheetDay): string {
  const parts = [`${row.name} · ${day.date}`, t(`admin.timeAttendance.statuses.${day.status}`)]
  if (day.check_in_at) parts.push(`${clock(day.check_in_at)} – ${clock(day.check_out_at)}`)
  if (day.late_minutes) parts.push(t('admin.timeAttendance.lateBy', { minutes: day.late_minutes }))
  if (day.early_leave_minutes) parts.push(t('admin.timeAttendance.earlyBy', { minutes: day.early_leave_minutes }))
  if (day.shift) parts.push(day.shift.name)
  return parts.join('\n')
}

// --- Editing a day -----------------------------------------------------------------

const entryOpen = ref(false)
const preset = ref<{ staffId: number; date: string; checkIn?: string | null; checkOut?: string | null } | null>(null)

function openDay(row: SheetRow, day: SheetDay) {
  if (!canManage.value || row.locked || day.status === 'none' || day.status === 'upcoming') return
  preset.value = { staffId: row.id, date: day.date, checkIn: day.check_in_at, checkOut: day.check_out_at }
  entryOpen.value = true
}

onMounted(async () => {
  void load()
  const [departmentRows, staffRows] = await Promise.all([departmentsService.listAll().catch(() => []), staffService.listAll().catch(() => [])])
  departments.value = departmentRows
  staff.value = staffRows
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <BaseInput v-model="month" type="month" class="w-40" @update:model-value="load" />
      <BaseSelect class="w-48" :model-value="departmentId" :options="departmentOptions" @update:model-value="(v: string) => { departmentId = v; load() }" />
      <input
        type="search"
        :placeholder="t('common.searchPlaceholder')"
        class="block w-full max-w-xs rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="onSearch(($event.target as HTMLInputElement).value)"
      />
    </div>

    <!-- Legend -->
    <div class="mb-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-neutral-600">
      <span v-for="status in DAY_STATUSES" :key="status" class="flex items-center gap-1">
        <span class="inline-flex h-5 min-w-5 items-center justify-center rounded px-1 font-bold" :class="DAY_STYLE[status].cls">{{ DAY_STYLE[status].short }}</span>
        {{ t(`admin.timeAttendance.statuses.${status}`) }}
      </span>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
    <p v-else-if="rows.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
      {{ t('admin.timeAttendance.noStaff') }}
    </p>

    <template v-else>
      <!-- Phone: a card per person -->
      <div class="space-y-2 sm:hidden">
        <div v-for="row in rows" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <p class="text-sm font-semibold text-neutral-800">
            <span v-if="row.locked" :title="t('admin.timeAttendance.signOff.lockedHint')">🔒 </span>{{ row.name }} <span class="font-normal text-neutral-500">{{ row.employee_code }}</span>
          </p>
          <p class="mt-1 text-xs text-neutral-600">
            {{ t('admin.timeAttendance.statuses.present') }} {{ row.totals.present + row.totals.late + row.totals.early_leave }} ·
            {{ t('admin.timeAttendance.statuses.late') }} {{ row.totals.late }} ·
            {{ t('admin.timeAttendance.statuses.absent') }} {{ row.totals.absent }} ·
            {{ t('admin.timeAttendance.statuses.leave') }} {{ row.totals.leave }}
          </p>
          <div class="mt-2 flex flex-wrap gap-0.5">
            <button
              v-for="day in row.days"
              :key="day.date"
              type="button"
              class="flex h-6 w-6 items-center justify-center rounded text-[10px] font-bold"
              :class="DAY_STYLE[day.status].cls || 'bg-neutral-50 text-neutral-300'"
              :title="cellTitle(row, day)"
              @click="openDay(row, day)"
            >
              {{ DAY_STYLE[day.status].short || dayNumber(day.date) }}
            </button>
          </div>
        </div>
      </div>

      <!-- Computer: the month grid -->
      <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block">
        <table class="min-w-full border-collapse text-xs">
          <thead>
            <tr class="bg-neutral-50">
              <th class="sticky left-0 z-10 min-w-44 bg-neutral-50 px-3 py-2 text-left font-semibold text-neutral-700">{{ t('admin.timeAttendance.entry.staff') }}</th>
              <th v-for="date in dates" :key="date" class="w-7 px-0.5 py-1 text-center font-medium" :class="isWeekend(date) ? 'text-neutral-400' : 'text-neutral-600'">
                <div>{{ weekday(date) }}</div>
                <div>{{ dayNumber(date) }}</div>
              </th>
              <th class="px-2 text-center font-semibold text-success-600" :title="t('admin.timeAttendance.statuses.present')">✓</th>
              <th class="px-2 text-center font-semibold text-amber-700" :title="t('admin.timeAttendance.statuses.late')">L</th>
              <th class="px-2 text-center font-semibold text-red-700" :title="t('admin.timeAttendance.statuses.absent')">A</th>
              <th class="px-2 text-center font-semibold text-sky-700" :title="t('admin.timeAttendance.statuses.leave')">LV</th>
              <th class="px-2 text-right font-semibold text-neutral-700">{{ t('admin.timeAttendance.worked') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.id" class="border-t border-neutral-100">
              <td class="sticky left-0 z-10 bg-white px-3 py-1.5">
                <p class="truncate font-medium text-neutral-800">
                  <span v-if="row.locked" :title="t('admin.timeAttendance.signOff.lockedHint')">🔒 </span>{{ row.name }}
                </p>
                <p class="text-neutral-400">{{ row.employee_code }}</p>
              </td>
              <td v-for="day in row.days" :key="day.date" class="p-0.5 text-center">
                <button
                  type="button"
                  class="flex h-6 w-6 items-center justify-center rounded font-bold"
                  :class="[DAY_STYLE[day.status].cls, canManage && !row.locked && day.status !== 'none' && day.status !== 'upcoming' ? 'hover:ring-2 hover:ring-primary-300' : 'cursor-default']"
                  :title="cellTitle(row, day)"
                  @click="openDay(row, day)"
                >
                  {{ DAY_STYLE[day.status].short }}
                </button>
              </td>
              <td class="px-2 text-center">{{ row.totals.present + row.totals.late + row.totals.early_leave }}</td>
              <td class="px-2 text-center">{{ row.totals.late }}</td>
              <td class="px-2 text-center">{{ row.totals.absent }}</td>
              <td class="px-2 text-center">{{ row.totals.leave }}</td>
              <td class="whitespace-nowrap px-2 text-right">{{ formatMinutes(row.totals.worked_minutes) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <AttendanceEntryModal v-model="entryOpen" :staff="staff" :preset="preset" @saved="load" />
  </div>
</template>
