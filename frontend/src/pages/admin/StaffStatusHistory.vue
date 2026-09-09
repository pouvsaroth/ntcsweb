<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { lookupsService } from '@/services/lookups'
import { staffService } from '@/services/staff'
import { staffStatusHistoriesService, type StaffStatusHistoryEntry } from '@/services/staffStatusHistories'

const { t, locale } = useI18n()

const statusBadgeVariant: Record<string, 'success' | 'neutral' | 'danger' | 'warning' | 'primary'> = {
  active: 'success',
  probation: 'warning',
  on_leave: 'warning',
  suspended: 'danger',
  resigned: 'neutral',
  terminated: 'danger',
  retired: 'neutral',
}

// Labels come from the same STAFF_STATUS base-data lookup the dropdown/badge
// on the Staff list itself reads — never duplicated into i18n keys.
const statusLabels = ref<Record<string, string>>({})
const statusOptions = ref<{ value: string; label: string }[]>([])
const staffOptions = ref<{ value: string; label: string }[]>([])

const dateFrom = ref('')
const dateTo = ref('')
const selectedStaffId = ref('')
const selectedStatus = ref('')

const { items, meta, loading, error, setPage, setFilter, fetch } = usePaginatedResource<StaffStatusHistoryEntry>((query) =>
  staffStatusHistoriesService.list({ ...query, date_from: dateFrom.value || undefined, date_to: dateTo.value || undefined }),
)

function onStaffFilterChange(value: string) {
  selectedStaffId.value = value
  setFilter('staff_id', value || undefined)
}

function onStatusFilterChange(value: string) {
  selectedStatus.value = value
  setFilter('to_status', value || undefined)
}

function resetFilters() {
  dateFrom.value = ''
  dateTo.value = ''
  selectedStaffId.value = ''
  selectedStatus.value = ''
  setFilter('staff_id', undefined)
  setFilter('to_status', undefined)
  void fetch()
}

const columns = [
  { key: 'staff', label: t('admin.staffStatusHistory.columnStaff') },
  { key: 'from_status', label: t('admin.staffStatusHistory.columnFrom') },
  { key: 'to_status', label: t('admin.staffStatusHistory.columnTo') },
  { key: 'requested_date', label: t('admin.staffStatusHistory.columnRequestedDate') },
  { key: 'effective_date', label: t('admin.staffStatusHistory.columnEffectiveDate') },
  { key: 'reason', label: t('admin.staffStatusHistory.columnReason') },
  { key: 'changed_by', label: t('admin.staffStatusHistory.columnChangedBy') },
  { key: 'created_at', label: t('admin.staffStatusHistory.columnChangedAt') },
]

function formatDate(value: string): string {
  return new Date(value).toLocaleString()
}

onMounted(async () => {
  void fetch()

  const options = await lookupsService.values('STAFF_STATUS', locale.value)
  statusLabels.value = Object.fromEntries(options.map((option) => [option.code, option.name]))
  statusOptions.value = options.map((option) => ({ value: option.code, label: option.name }))

  const staff = await staffService.listAll()
  staffOptions.value = staff.map((member) => ({ value: String(member.id), label: member.full_name }))
})
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.staffStatusHistory.title') }}</h1>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div class="mb-4 grid gap-3 rounded-[--radius-card] border border-neutral-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-5">
      <BaseSelect
        :model-value="selectedStaffId"
        :options="staffOptions"
        :placeholder="t('admin.staffStatusHistory.filterAllStaff')"
        @update:model-value="onStaffFilterChange"
      />
      <BaseSelect
        :model-value="selectedStatus"
        :options="statusOptions"
        :placeholder="t('admin.staffStatusHistory.filterAllStatuses')"
        @update:model-value="onStatusFilterChange"
      />
      <div class="flex gap-2">
        <input v-model="dateFrom" type="date" class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200" />
        <input v-model="dateTo" type="date" class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200" />
      </div>
      <div class="flex justify-end gap-2 lg:col-span-2">
        <BaseButton variant="outline" size="sm" @click="resetFilters">{{ t('admin.staffStatusHistory.reset') }}</BaseButton>
        <BaseButton size="sm" @click="fetch()">{{ t('admin.staffStatusHistory.search') }}</BaseButton>
      </div>
    </div>

    <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.staffStatusHistory.emptyMessage')">
      <template #cell-staff="{ row }">
        <p class="font-medium text-neutral-800">{{ row.staff.full_name }}</p>
        <p class="text-xs text-neutral-500">{{ row.staff.employee_code }}</p>
      </template>
      <template #cell-from_status="{ row }">
        <BaseBadge :variant="statusBadgeVariant[row.from_status] ?? 'neutral'">{{ statusLabels[row.from_status] ?? row.from_status }}</BaseBadge>
      </template>
      <template #cell-to_status="{ row }">
        <BaseBadge :variant="statusBadgeVariant[row.to_status] ?? 'neutral'">{{ statusLabels[row.to_status] ?? row.to_status }}</BaseBadge>
      </template>
      <template #cell-requested_date="{ row }">{{ row.requested_date ?? '—' }}</template>
      <template #cell-effective_date="{ row }">{{ row.effective_date ?? '—' }}</template>
      <template #cell-reason="{ row }">
        <span class="line-clamp-1">{{ row.reason ?? '—' }}</span>
      </template>
      <template #cell-changed_by="{ row }">{{ row.changed_by ?? '—' }}</template>
      <template #cell-created_at="{ row }">{{ formatDate(row.created_at) }}</template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />
  </div>
</template>
