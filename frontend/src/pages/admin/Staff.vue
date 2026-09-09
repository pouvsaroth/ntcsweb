<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

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
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.staff.title') }}</h1>
      </div>
      <BaseButton to="/admin/staff/new">{{ t('admin.staff.addStaff') }}</BaseButton>
    </div>

    <div class="mb-4">
      <input
        type="search"
        :placeholder="t('common.searchPlaceholder')"
        class="block w-full max-w-sm rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
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
        <div class="flex flex-wrap justify-end gap-x-3 gap-y-1">
          <EditIconButton :to="`/admin/staff/${row.id}/edit`" />
          <button type="button" class="text-sm font-medium text-neutral-600 hover:text-neutral-800" @click="openStatusModal(row)">
            {{ t('admin.staff.changeStatus') }}
          </button>
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.staff.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <StaffStatusModal v-model="statusModalOpen" :staff="activeStaff" @saved="fetch" />
  </div>
</template>
