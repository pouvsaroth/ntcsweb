<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import StaffFormModal from '@/components/admin/StaffFormModal.vue'
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
  { key: 'name', label: t('admin.staff.columnName') },
  { key: 'employee_code', label: t('admin.staff.columnCode') },
  { key: 'position', label: t('admin.staff.columnPosition') },
  { key: 'phone', label: t('admin.staff.columnPhone') },
  { key: 'status', label: t('admin.staff.columnStatus') },
  { key: 'actions', label: t('admin.staff.columnActions'), align: 'text-right' },
])

const modalOpen = ref(false)
const editingStaff = ref<Staff | null>(null)

function openCreate() {
  editingStaff.value = null
  modalOpen.value = true
}

function openEdit(staff: Staff) {
  editingStaff.value = staff
  modalOpen.value = true
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
      <BaseButton @click="openCreate">{{ t('admin.staff.addStaff') }}</BaseButton>
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
      <template #cell-position="{ row }">{{ row.position?.name ?? '—' }}</template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="row.status === 'active' ? 'success' : 'neutral'">
          {{ row.status === 'active' ? t('admin.staff.statusActive') : t('admin.staff.statusInactive') }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton @click="openEdit(row)" />
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.staff.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <StaffFormModal v-model="modalOpen" :staff="editingStaff" @saved="fetch" />
  </div>
</template>
