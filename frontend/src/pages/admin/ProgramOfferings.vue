<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import ProgramOfferingFormModal from '@/components/admin/ProgramOfferingFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { programOfferingsService, type ProgramOffering } from '@/services/programOfferings'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()

const { items, meta, loading, error, setPage, fetch } = usePaginatedResource<ProgramOffering>((query) => programOfferingsService.list(query))

const columns = [
  { key: 'name', label: t('admin.programOfferings.columnName') },
  { key: 'program', label: t('admin.programOfferings.columnProgram') },
  { key: 'study_mode', label: t('admin.programOfferings.columnStudyMode') },
  { key: 'academic_year', label: t('admin.programOfferings.columnAcademicYear') },
  { key: 'status', label: t('admin.programOfferings.columnStatus') },
  { key: 'actions', label: t('admin.programOfferings.columnActions'), align: 'text-right' },
]

const modalOpen = ref(false)
const editingOffering = ref<ProgramOffering | null>(null)
const deleteError = ref<string | null>(null)

function openCreate() {
  editingOffering.value = null
  modalOpen.value = true
}

function openEdit(offering: ProgramOffering) {
  editingOffering.value = offering
  modalOpen.value = true
}

async function remove(offering: ProgramOffering) {
  if (!window.confirm(t('admin.programOfferings.deleteConfirm'))) return
  deleteError.value = null

  try {
    await programOfferingsService.remove(offering.id)
    await fetch()
  } catch (e) {
    deleteError.value = e instanceof ApiRequestError ? e.message : t('admin.programOfferings.deleteFailed')
  }
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.programOfferings.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.programOfferings.pageSubtitle') }}</p>
      </div>
      <BaseButton @click="openCreate">{{ t('admin.programOfferings.addOffering') }}</BaseButton>
    </div>

    <BaseAlert v-if="error || deleteError" variant="danger" class="mb-4">{{ error || deleteError }}</BaseAlert>

    <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.programOfferings.emptyMessage')">
      <template #cell-program="{ row }">{{ row.academic_program?.code ?? '—' }}</template>
      <template #cell-study_mode="{ row }">{{ row.study_mode?.name ?? '—' }}</template>
      <template #cell-academic_year="{ row }">{{ row.academic_year?.name ?? '—' }}</template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="row.status === 'active' ? 'success' : 'neutral'">
          {{ row.status === 'active' ? t('admin.programOfferings.statusActive') : t('admin.programOfferings.statusClosed') }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton @click="openEdit(row)" />
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.programOfferings.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <ProgramOfferingFormModal v-model="modalOpen" :offering="editingOffering" @saved="fetch" />
  </div>
</template>
