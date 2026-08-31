<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { staffService, type Staff } from '@/services/staff'

const { t } = useI18n()

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

async function remove(staff: Staff) {
  if (!window.confirm(t('admin.staff.deleteConfirm'))) return
  await staffService.remove(staff.id)
  await fetch()
}

onMounted(() => fetch())
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
        <BaseBadge :variant="row.status === 'active' ? 'success' : 'neutral'">
          {{ row.status === 'active' ? t('admin.staff.statusActive') : t('admin.staff.statusInactive') }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton :to="`/admin/staff/${row.id}/edit`" />
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.staff.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />
  </div>
</template>
