<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import StudyModeFormModal from '@/components/admin/StudyModeFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { studyModesService, type StudyMode } from '@/services/studyModes'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()

const { items, meta, loading, error, setPage, fetch } = usePaginatedResource<StudyMode>((query) => studyModesService.list(query))

const columns = [
  { key: 'code', label: t('admin.studyModes.columnCode') },
  { key: 'name', label: t('admin.studyModes.columnName') },
  { key: 'is_active', label: t('admin.studyModes.columnStatus') },
  { key: 'actions', label: t('admin.studyModes.columnActions'), align: 'text-right' },
]

const modalOpen = ref(false)
const editingMode = ref<StudyMode | null>(null)
const deleteError = ref<string | null>(null)

function openCreate() {
  editingMode.value = null
  modalOpen.value = true
}

function openEdit(mode: StudyMode) {
  editingMode.value = mode
  modalOpen.value = true
}

async function remove(mode: StudyMode) {
  if (!window.confirm(t('admin.studyModes.deleteConfirm'))) return
  deleteError.value = null

  try {
    await studyModesService.remove(mode.id)
    await fetch()
  } catch (e) {
    deleteError.value = e instanceof ApiRequestError ? e.message : t('admin.studyModes.deleteFailed')
  }
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.studyModes.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.studyModes.pageSubtitle') }}</p>
      </div>
      <BaseButton @click="openCreate">{{ t('admin.studyModes.addStudyMode') }}</BaseButton>
    </div>

    <BaseAlert v-if="error || deleteError" variant="danger" class="mb-4">{{ error || deleteError }}</BaseAlert>

    <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.studyModes.emptyMessage')">
      <template #cell-is_active="{ row }">
        <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">
          {{ row.is_active ? t('admin.studyModes.statusActive') : t('admin.studyModes.statusInactive') }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton @click="openEdit(row)" />
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.studyModes.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <StudyModeFormModal v-model="modalOpen" :study-mode="editingMode" @saved="fetch" />
  </div>
</template>
