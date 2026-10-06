<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import AttendanceEntryModal from '@/components/admin/AttendanceEntryModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { staffService, type Staff } from '@/services/staff'
import { clock, staffAttendanceService, STATUS_VARIANT, type DayStatus, type ImportResult, type StaffAttendanceRecord } from '@/services/staffAttendance'
import { formatMinutes } from '@/services/timeAttendance'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * HRM > Attendance & Time > Check-in / check-out: every recorded day — from
 * the app, typed in by HR, or imported from a fingerprint machine — with
 * where it came from. HR adds or fixes a day, or imports a CSV. Cards on a
 * phone, a table from `sm` up.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const canManage = computed(() => auth.can('staff-attendance.manage'))

function isoDay(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
const today = new Date()
const from = ref(isoDay(new Date(today.getFullYear(), today.getMonth(), 1)))
const to = ref(isoDay(today))
const statusFilter = ref('')
const sourceFilter = ref('')

const { items, meta, loading, error, setPage, setSearch, setFilter, fetch } = usePaginatedResource<StaffAttendanceRecord>((query) =>
  staffAttendanceService.list(query, { ...(from.value ? { from: from.value } : {}), ...(to.value ? { to: to.value } : {}) }),
)

const statusOptions = computed(() => [
  { value: '', label: t('admin.recruitment.manpower.allStatuses') },
  ...(['present', 'late', 'early_leave', 'incomplete'] as DayStatus[]).map((s) => ({ value: s, label: t(`admin.timeAttendance.statuses.${s}`) })),
])
const sourceOptions = computed(() => [
  { value: '', label: t('admin.timeAttendance.allSources') },
  ...(['app', 'manual', 'import', 'correction'] as const).map((s) => ({ value: s, label: t(`admin.timeAttendance.sources.${s}`) })),
])

function filterBy(key: 'status' | 'check_in_source', value: string) {
  if (key === 'status') statusFilter.value = value
  else sourceFilter.value = value
  setFilter(key, value || undefined)
}

const columns = computed(() => [
  { key: 'date', label: t('admin.timeAttendance.holidays.date') },
  { key: 'staff', label: t('admin.timeAttendance.entry.staff') },
  { key: 'times', label: `${t('admin.timeAttendance.checkIn')} – ${t('admin.timeAttendance.checkOut')}` },
  { key: 'worked', label: t('admin.timeAttendance.worked') },
  { key: 'status', label: t('admin.organization.status') },
  { key: 'source', label: t('admin.timeAttendance.source') },
  ...(canManage.value ? [{ key: 'actions', label: t('admin.organization.actions'), align: 'text-right' }] : []),
])

function mapLink(location: { lat: number; lng: number } | null): string | null {
  return location ? `https://www.google.com/maps?q=${location.lat},${location.lng}` : null
}

// --- Add / fix ------------------------------------------------------------------

const staff = ref<Staff[]>([])
const entryOpen = ref(false)
const preset = ref<{ staffId: number; date: string; checkIn?: string | null; checkOut?: string | null; note?: string | null } | null>(null)
const actionError = ref<string | null>(null)

function openAdd() {
  preset.value = null
  entryOpen.value = true
}

function openEdit(row: StaffAttendanceRecord) {
  preset.value = { staffId: row.staff_id, date: row.date, checkIn: row.check_in_at, checkOut: row.check_out_at, note: row.note }
  entryOpen.value = true
}

async function remove(row: StaffAttendanceRecord) {
  if (!(await confirmDialog.confirm({ message: t('admin.timeAttendance.deleteDayConfirm', { name: row.staff?.name ?? '', date: formatDate(row.date) }), danger: true }))) return
  actionError.value = null
  try {
    await staffAttendanceService.remove(row.id)
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.organization.deleteFailed')
  }
}

// --- Import ------------------------------------------------------------------------

const importOpen = ref(false)
const importFile = ref<File | null>(null)
const importing = ref(false)
const importResult = ref<ImportResult | null>(null)
const importError = ref<string | null>(null)

function openImport() {
  importFile.value = null
  importResult.value = null
  importError.value = null
  importOpen.value = true
}

async function runImport() {
  if (!importFile.value) return
  importing.value = true
  importError.value = null
  try {
    importResult.value = await staffAttendanceService.import(importFile.value)
    await fetch()
  } catch (e) {
    importError.value = e instanceof ApiRequestError ? (e.errors?.file?.[0] ?? e.message) : t('admin.timeAttendance.import.failed')
  } finally {
    importing.value = false
  }
}

onMounted(async () => {
  void fetch()
  staff.value = await staffService.listAll().catch(() => [])
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-end gap-2">
      <BaseInput v-model="from" type="date" class="w-40" :label="t('admin.timeAttendance.holidays.from')" @update:model-value="fetch" />
      <BaseInput v-model="to" type="date" class="w-40" :label="t('admin.timeAttendance.holidays.to')" @update:model-value="fetch" />
      <BaseSelect class="w-40" :model-value="statusFilter" :options="statusOptions" @update:model-value="(v: string) => filterBy('status', v)" />
      <BaseSelect class="w-40" :model-value="sourceFilter" :options="sourceOptions" @update:model-value="(v: string) => filterBy('check_in_source', v)" />
      <input
        type="search"
        :placeholder="t('common.searchPlaceholder')"
        class="block h-[38px] w-full max-w-xs rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <div v-if="canManage" class="ml-auto flex gap-2">
        <BaseButton variant="outline" @click="openImport">{{ t('admin.timeAttendance.import.button') }}</BaseButton>
        <BaseButton @click="openAdd">{{ t('admin.timeAttendance.entry.add') }}</BaseButton>
      </div>
    </div>

    <BaseAlert v-if="error || actionError" variant="danger" class="mb-4">{{ error || actionError }}</BaseAlert>

    <!-- Cards on a phone (below sm) -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.timeAttendance.noRecords') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate text-sm font-semibold text-neutral-800">{{ row.staff?.name }}</p>
              <p class="text-xs text-neutral-500">{{ formatDate(row.date) }}<template v-if="row.shift"> · {{ row.shift.name }}</template></p>
            </div>
            <BaseBadge :variant="STATUS_VARIANT[row.status]" class="shrink-0">{{ t(`admin.timeAttendance.statuses.${row.status}`) }}</BaseBadge>
          </div>
          <p class="mt-2 text-sm text-neutral-800">{{ clock(row.check_in_at) }} – {{ clock(row.check_out_at) }} · {{ formatMinutes(row.worked_minutes) }}</p>
          <p class="text-xs text-neutral-500">
            <template v-if="row.late_minutes">{{ t('admin.timeAttendance.lateBy', { minutes: row.late_minutes }) }} · </template>
            <template v-if="row.early_leave_minutes">{{ t('admin.timeAttendance.earlyBy', { minutes: row.early_leave_minutes }) }} · </template>
            {{ row.check_in_source ? t(`admin.timeAttendance.sources.${row.check_in_source}`) : '' }}
          </p>
          <div v-if="canManage" class="mt-2 flex justify-end gap-3">
            <EditIconButton @click="openEdit(row)" />
            <button type="button" class="text-sm font-medium text-danger-600" @click="remove(row)">{{ t('admin.organization.delete') }}</button>
          </div>
        </div>
      </div>
    </div>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.timeAttendance.noRecords')">
        <template #cell-date="{ row }">
          <p>{{ formatDate(row.date) }}</p>
          <p v-if="row.shift" class="text-xs text-neutral-500">{{ row.shift.name }} {{ row.shift.start_time }}–{{ row.shift.end_time }}</p>
        </template>
        <template #cell-staff="{ row }">
          <p class="font-medium text-neutral-800">{{ row.staff?.name }}</p>
          <p class="text-xs text-neutral-500">{{ row.staff?.employee_code }}</p>
        </template>
        <template #cell-times="{ row }">
          <p class="font-medium tabular-nums">{{ clock(row.check_in_at) }} – {{ clock(row.check_out_at) }}</p>
          <p class="text-xs">
            <span v-if="row.late_minutes" class="text-amber-700">{{ t('admin.timeAttendance.lateBy', { minutes: row.late_minutes }) }} </span>
            <span v-if="row.early_leave_minutes" class="text-orange-700">{{ t('admin.timeAttendance.earlyBy', { minutes: row.early_leave_minutes }) }}</span>
          </p>
        </template>
        <template #cell-worked="{ row }">
          {{ formatMinutes(row.worked_minutes) }}
          <p v-if="row.overtime_minutes" class="text-xs text-primary-700">+{{ formatMinutes(row.overtime_minutes) }}</p>
        </template>
        <template #cell-status="{ row }">
          <BaseBadge :variant="STATUS_VARIANT[row.status as DayStatus]">{{ t(`admin.timeAttendance.statuses.${row.status}`) }}</BaseBadge>
        </template>
        <template #cell-source="{ row }">
          <p>{{ row.check_in_source ? t(`admin.timeAttendance.sources.${row.check_in_source}`) : '—' }}</p>
          <a v-if="mapLink(row.check_in_location)" :href="mapLink(row.check_in_location)!" target="_blank" rel="noopener" class="text-xs text-primary-700 hover:underline">
            {{ t('admin.timeAttendance.onMap') }}
          </a>
          <p v-if="row.note" class="max-w-40 truncate text-xs text-neutral-500" :title="row.note">{{ row.note }}</p>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-2">
            <EditIconButton @click="openEdit(row as StaffAttendanceRecord)" />
            <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row as StaffAttendanceRecord)">{{ t('admin.organization.delete') }}</button>
          </div>
        </template>
      </DataTable>
    </div>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <AttendanceEntryModal v-model="entryOpen" :staff="staff" :preset="preset" @saved="fetch" />

    <BaseModal v-model="importOpen" :title="t('admin.timeAttendance.import.title')">
      <div class="space-y-4 text-sm">
        <p class="text-neutral-600">{{ t('admin.timeAttendance.import.hint') }}</p>
        <pre class="overflow-x-auto rounded-lg bg-neutral-50 p-3 text-xs text-neutral-700">employee_code,date,time
NTSS-0001,2026-10-12,07:58
NTSS-0001,2026-10-12,17:03</pre>
        <p class="text-xs text-neutral-500">{{ t('admin.timeAttendance.import.altHint') }}</p>
        <input
          type="file"
          accept=".csv,text/csv"
          class="block w-full text-sm text-neutral-600 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-800 hover:file:bg-primary-100"
          @change="importFile = ($event.target as HTMLInputElement).files?.[0] ?? null"
        />
        <BaseAlert v-if="importError" variant="danger">{{ importError }}</BaseAlert>
        <BaseAlert v-if="importResult" :variant="importResult.errors.length ? 'warning' : 'success'">
          {{ t('admin.timeAttendance.import.done', { days: importResult.days, rows: importResult.rows }) }}
          <ul v-if="importResult.errors.length" class="mt-2 list-disc pl-5 text-xs">
            <li v-for="message in importResult.errors" :key="message">{{ message }}</li>
          </ul>
        </BaseAlert>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="importOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="importing" :disabled="!importFile" @click="runImport">{{ t('admin.timeAttendance.import.run') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
