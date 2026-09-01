<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import DepartmentFormModal from '@/components/admin/DepartmentFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { departmentsService, type Department } from '@/services/departments'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()

const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<Department>((query) => departmentsService.list(query))

const columns = computed(() => [
  { key: 'code', label: t('admin.departments.columnCode') },
  { key: 'name', label: t('admin.departments.columnName') },
  { key: 'is_active', label: t('admin.departments.columnStatus') },
  { key: 'actions', label: t('admin.departments.columnActions'), align: 'text-right' },
])

const modalOpen = ref(false)
const editingDepartment = ref<Department | null>(null)
const deleteError = ref<string | null>(null)

function openCreate() {
  editingDepartment.value = null
  modalOpen.value = true
}

function openEdit(department: Department) {
  editingDepartment.value = department
  modalOpen.value = true
}

async function remove(department: Department) {
  if (!window.confirm(t('admin.departments.deleteConfirm'))) return
  deleteError.value = null

  try {
    await departmentsService.remove(department.id)
    await fetch()
  } catch (e) {
    deleteError.value = e instanceof ApiRequestError ? e.message : t('admin.departments.deleteFailed')
  }
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.departments.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.departments.pageSubtitle') }}</p>
      </div>
      <BaseButton @click="openCreate">{{ t('admin.departments.addDepartment') }}</BaseButton>
    </div>

    <div class="mb-4">
      <input
        type="search"
        :placeholder="t('common.searchPlaceholder')"
        class="block w-full max-w-sm rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
    </div>

    <BaseAlert v-if="error || deleteError" variant="danger" class="mb-4">{{ error || deleteError }}</BaseAlert>

    <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.departments.emptyMessage')">
      <template #cell-is_active="{ row }">
        <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">
          {{ row.is_active ? t('admin.departments.statusActive') : t('admin.departments.statusInactive') }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton @click="openEdit(row)" />
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.departments.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <DepartmentFormModal v-model="modalOpen" :department="editingDepartment" @saved="fetch" />
  </div>
</template>
