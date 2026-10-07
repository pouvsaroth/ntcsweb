<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { ABSENCE_STATUSES, attendanceService, type AttendanceRecord, type AttendanceStatusValue } from '@/services/attendance'
import { formatDate } from '@/utils/date'

const { t } = useI18n()

const { items, meta, loading, error, setPage, setFilter, fetch } = usePaginatedResource<AttendanceRecord>((query) =>
  attendanceService.myList(query),
)

const onlyAbsent = ref(false)

function onOnlyAbsentChange(checked: boolean) {
  onlyAbsent.value = checked
  setFilter('status', checked ? ABSENCE_STATUSES.join(',') : undefined)
}

const columns = [
  { key: 'date', label: t('admin.myAttendance.columnDate') },
  { key: 'class', label: t('admin.myAttendance.columnClass') },
  { key: 'from_time', label: t('admin.myAttendance.columnFromTime') },
  { key: 'to_time', label: t('admin.myAttendance.columnToTime') },
  { key: 'status', label: t('admin.myAttendance.columnStatus') },
  { key: 'late_minutes', label: t('admin.myAttendance.columnLateMinutes') },
  { key: 'remarks', label: t('admin.myAttendance.columnRemarks') },
]

const statusVariant: Record<AttendanceStatusValue, 'success' | 'danger' | 'warning' | 'neutral'> = {
  PRESENT: 'success',
  ABSENT: 'danger',
  LATE: 'warning',
  EXCUSED: 'neutral',
}

function statusLabel(status: AttendanceStatusValue): string {
  return t(`admin.attendance.status${status.charAt(0)}${status.slice(1).toLowerCase()}`)
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.myAttendance.title') }}</h1>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <label class="mb-4 flex items-center gap-2 text-sm text-neutral-700">
      <input
        type="checkbox"
        class="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
        :checked="onlyAbsent"
        @change="onOnlyAbsentChange(($event.target as HTMLInputElement).checked)"
      />
      {{ t('admin.myAttendance.onlyAbsent') }}
    </label>

    <!-- Cards on small screens — a table's columns don't have room to breathe
         on a phone; below sm: this replaces the DataTable entirely. -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.myAttendance.emptyMessage') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-center justify-between gap-2">
            <p class="text-sm font-medium text-neutral-800">{{ formatDate(row.date) }}</p>
            <BaseBadge :variant="statusVariant[row.status]">{{ statusLabel(row.status) }}</BaseBadge>
          </div>
          <p class="mt-1 truncate text-sm text-neutral-600">{{ row.class?.name }}</p>
          <p class="text-xs text-neutral-500">{{ row.class?.start_time ?? '—' }} – {{ row.class?.end_time ?? '—' }}</p>
          <p v-if="row.status === 'LATE' && row.late_minutes != null" class="mt-1 text-xs text-neutral-500">
            {{ t('admin.myAttendance.columnLateMinutes') }}: {{ row.late_minutes }}
          </p>
          <p v-if="row.remarks" class="mt-1 text-xs text-neutral-500">{{ row.remarks }}</p>
        </div>
      </div>
    </div>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.myAttendance.emptyMessage')">
        <template #cell-date="{ row }">{{ formatDate(row.date) }}</template>
        <template #cell-class="{ row }">{{ row.class?.name }}</template>
        <template #cell-from_time="{ row }">{{ row.class?.start_time ?? '—' }}</template>
        <template #cell-to_time="{ row }">{{ row.class?.end_time ?? '—' }}</template>
        <template #cell-status="{ row }">
          <BaseBadge :variant="statusVariant[row.status]">{{ statusLabel(row.status) }}</BaseBadge>
        </template>
        <template #cell-late_minutes="{ row }">{{ row.status === 'LATE' && row.late_minutes != null ? row.late_minutes : '—' }}</template>
        <template #cell-remarks="{ row }">{{ row.remarks ?? '—' }}</template>
      </DataTable>
    </div>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />
  </div>
</template>
