<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import AcademicYearFormModal from '@/components/admin/AcademicYearFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { academicYearsService, type AcademicYear } from '@/services/academicYears'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()

const { items, meta, loading, error, setPage, fetch } = usePaginatedResource<AcademicYear>((query) => academicYearsService.list(query))

const columns = [
  { key: 'name', label: t('admin.academicYears.columnName') },
  { key: 'start_date', label: t('admin.academicYears.columnStartDate') },
  { key: 'end_date', label: t('admin.academicYears.columnEndDate') },
  { key: 'is_current', label: t('admin.academicYears.columnCurrent') },
  { key: 'actions', label: t('admin.academicYears.columnActions'), align: 'text-right' },
]

const modalOpen = ref(false)
const editingYear = ref<AcademicYear | null>(null)
const deleteError = ref<string | null>(null)

function openCreate() {
  editingYear.value = null
  modalOpen.value = true
}

function openEdit(year: AcademicYear) {
  editingYear.value = year
  modalOpen.value = true
}

async function remove(year: AcademicYear) {
  if (!window.confirm(t('admin.academicYears.deleteConfirm'))) return
  deleteError.value = null

  try {
    await academicYearsService.remove(year.id)
    await fetch()
  } catch (e) {
    deleteError.value = e instanceof ApiRequestError ? e.message : t('admin.academicYears.deleteFailed')
  }
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.academicYears.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.academicYears.pageSubtitle') }}</p>
      </div>
      <BaseButton @click="openCreate">{{ t('admin.academicYears.addYear') }}</BaseButton>
    </div>

    <BaseAlert v-if="error || deleteError" variant="danger" class="mb-4">{{ error || deleteError }}</BaseAlert>

    <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.academicYears.emptyMessage')">
      <template #cell-start_date="{ row }">{{ row.start_date ?? '—' }}</template>
      <template #cell-end_date="{ row }">{{ row.end_date ?? '—' }}</template>
      <template #cell-is_current="{ row }">
        <BaseBadge v-if="row.is_current" variant="success">{{ t('admin.academicYears.current') }}</BaseBadge>
        <span v-else>—</span>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton @click="openEdit(row)" />
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.academicYears.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <AcademicYearFormModal v-model="modalOpen" :academic-year="editingYear" @saved="fetch" />
  </div>
</template>
