<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import AcademicProgramFormModal from '@/components/admin/AcademicProgramFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { academicProgramsService, type AcademicProgram } from '@/services/academicPrograms'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()

const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<AcademicProgram>((query) =>
  academicProgramsService.list(query),
)

const columns = [
  { key: 'code', label: t('admin.academicPrograms.columnCode') },
  { key: 'name', label: t('admin.academicPrograms.columnName') },
  { key: 'is_active', label: t('admin.academicPrograms.columnStatus') },
  { key: 'actions', label: t('admin.academicPrograms.columnActions'), align: 'text-right' },
]

const modalOpen = ref(false)
const editingProgram = ref<AcademicProgram | null>(null)
const deleteError = ref<string | null>(null)

function openCreate() {
  editingProgram.value = null
  modalOpen.value = true
}

function openEdit(program: AcademicProgram) {
  editingProgram.value = program
  modalOpen.value = true
}

async function remove(program: AcademicProgram) {
  if (!window.confirm(t('admin.academicPrograms.deleteConfirm'))) return
  deleteError.value = null

  try {
    await academicProgramsService.remove(program.id)
    await fetch()
  } catch (e) {
    deleteError.value = e instanceof ApiRequestError ? e.message : t('admin.academicPrograms.deleteFailed')
  }
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.academicPrograms.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.academicPrograms.pageSubtitle') }}</p>
      </div>
      <BaseButton @click="openCreate">{{ t('admin.academicPrograms.addProgram') }}</BaseButton>
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

    <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.academicPrograms.emptyMessage')">
      <template #cell-is_active="{ row }">
        <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">
          {{ row.is_active ? t('admin.academicPrograms.statusActive') : t('admin.academicPrograms.statusInactive') }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton @click="openEdit(row)" />
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.academicPrograms.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <AcademicProgramFormModal v-model="modalOpen" :program="editingProgram" @saved="fetch" />
  </div>
</template>
