<script setup lang="ts">
import { onMounted } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { attendanceService, type AttendanceRecord, type AttendanceStatusValue } from '@/services/attendance'

const { t } = useI18n()

const { items, meta, loading, error, setPage, fetch } = usePaginatedResource<AttendanceRecord>((query) =>
  attendanceService.myList(query),
)

const columns = [
  { key: 'date', label: t('admin.myAttendance.columnDate') },
  { key: 'class', label: t('admin.myAttendance.columnClass') },
  { key: 'status', label: t('admin.myAttendance.columnStatus') },
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

    <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.myAttendance.emptyMessage')">
      <template #cell-class="{ row }">{{ row.class?.name }}</template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="statusVariant[row.status]">{{ statusLabel(row.status) }}</BaseBadge>
      </template>
      <template #cell-remarks="{ row }">{{ row.remarks ?? '—' }}</template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />
  </div>
</template>
