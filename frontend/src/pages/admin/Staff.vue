<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import ActionIconButton from '@/components/ui/ActionIconButton.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import StaffStatusModal from '@/components/admin/StaffStatusModal.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { lookupsService } from '@/services/lookups'
import { staffService, type Staff } from '@/services/staff'

const { t, locale } = useI18n()

const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<Staff>((query) =>
  staffService.list(query),
)

const columns = computed(() => [
  { key: 'full_name', label: t('admin.staff.columnName') },
  { key: 'employee_code', label: t('admin.staff.columnCode') },
  { key: 'position', label: t('admin.staff.columnPosition') },
  { key: 'phone', label: t('admin.staff.columnPhone') },
  { key: 'status', label: t('admin.staff.columnStatus') },
  { key: 'actions', label: t('admin.staff.columnActions'), align: 'text-right' },
])

/** First letter of each name part, e.g. "John Smith" -> "JS" — same idea as AdminSidebar's single-letter fallback, extended to two letters since there's more room in a table row. */
function initials(staff: Staff): string {
  return `${staff.first_name.charAt(0)}${staff.last_name.charAt(0)}`.toUpperCase() || '?'
}

// Status labels come from the STAFF_STATUS base-data lookup (same source
// LookupSelect/StaffStatusModal read) rather than i18n keys, so renaming a
// status via the Lookup admin screens is reflected here with no code change.
// Badge color is the one thing the lookup data has no concept of, so that
// stays a small local map keyed by the stable code.
const statusLabels = ref<Record<string, string>>({})
const statusBadgeVariant: Record<string, 'success' | 'neutral' | 'danger' | 'warning' | 'primary'> = {
  active: 'success',
  probation: 'warning',
  on_leave: 'warning',
  suspended: 'danger',
  resigned: 'neutral',
  terminated: 'danger',
  retired: 'neutral',
}

async function loadStatusLabels() {
  const options = await lookupsService.values('STAFF_STATUS', locale.value)
  statusLabels.value = Object.fromEntries(options.map((option) => [option.code, option.name]))
}

const statusModalOpen = ref(false)
const activeStaff = ref<Staff | null>(null)

function openStatusModal(staff: Staff) {
  activeStaff.value = staff
  statusModalOpen.value = true
}

async function remove(staff: Staff) {
  if (!window.confirm(t('admin.staff.deleteConfirm'))) return
  await staffService.remove(staff.id)
  await fetch()
}

onMounted(() => {
  void fetch()
  void loadStatusLabels()
})
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <input
        type="search"
        :placeholder="t('common.searchPlaceholder')"
        class="block w-full max-w-sm rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseButton to="/admin/staff/new">{{ t('admin.staff.addStaff') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.staff.emptyMessage')">
      <template #cell-full_name="{ row }">
        <div class="flex items-center gap-3">
          <div
            class="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full text-xs font-semibold text-white"
            :style="{ backgroundColor: row.photo_url ? undefined : (row.profile_color ?? '#94A3B8') }"
          >
            <img v-if="row.photo_url" :src="row.photo_url" alt="" class="h-full w-full object-cover" />
            <template v-else>{{ initials(row) }}</template>
          </div>
          <span class="font-medium text-neutral-800">{{ row.full_name }}</span>
        </div>
      </template>
      <template #cell-position="{ row }">{{ row.position?.name ?? '—' }}</template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="statusBadgeVariant[row.status] ?? 'neutral'">
          {{ statusLabels[row.status] ?? row.status }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-1">
          <EditIconButton :to="`/admin/staff/${row.id}/edit`" />
          <ActionIconButton :title="t('admin.staff.changeStatus')" @click="openStatusModal(row)">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"
              />
            </svg>
          </ActionIconButton>
          <ActionIconButton variant="danger" :title="t('admin.staff.delete')" @click="remove(row)">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"
              />
            </svg>
          </ActionIconButton>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <StaffStatusModal v-model="statusModalOpen" :staff="activeStaff" @saved="fetch" />
  </div>
</template>
