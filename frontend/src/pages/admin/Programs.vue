<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import ProgramFormModal from '@/components/admin/ProgramFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { programsService, type Program, type ProgramLevel } from '@/services/programs'

const { t } = useI18n()

const { items, meta, loading, error, setPage, setSort, sort, fetch } = usePaginatedResource<Program>((query) =>
  programsService.list(query),
)

const columns = [
  { key: 'image_url', label: t('admin.programs.columnPreview') },
  { key: 'title', label: t('admin.programs.columnTitle'), sortable: true },
  { key: 'category', label: t('admin.programs.columnCategory') },
  { key: 'level', label: t('admin.programs.columnLevel') },
  { key: 'is_featured', label: t('admin.programs.columnFeatured') },
  { key: 'status', label: t('admin.programs.columnStatus') },
  { key: 'actions', label: t('admin.programs.columnActions'), align: 'text-right' },
]

const levelBadgeVariant: Record<ProgramLevel, 'success' | 'warning' | 'danger'> = {
  beginner: 'success',
  intermediate: 'warning',
  advanced: 'danger',
}

const modalOpen = ref(false)
const editingProgram = ref<Program | null>(null)

function openCreate() {
  editingProgram.value = null
  modalOpen.value = true
}

function openEdit(program: Program) {
  editingProgram.value = program
  modalOpen.value = true
}

async function remove(program: Program) {
  if (!window.confirm(t('admin.programs.deleteConfirm'))) return
  await programsService.remove(program.id)
  await fetch()
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.programs.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.programs.pageSubtitle') }}</p>
      </div>
      <BaseButton @click="openCreate">{{ t('admin.programs.addProgram') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <DataTable
      :columns="columns"
      :rows="items"
      row-key="id"
      :loading="loading"
      :sort="sort"
      :empty-message="t('admin.programs.emptyMessage')"
      @sort="(col) => setSort(sort === col ? `-${col}` : col)"
    >
      <template #cell-image_url="{ row }">
        <div class="h-12 w-20 overflow-hidden rounded-md bg-neutral-100">
          <img v-if="row.image_url" :src="row.image_url" alt="" class="h-full w-full object-cover" />
        </div>
      </template>
      <template #cell-title="{ row }">
        <p class="font-medium text-neutral-800">{{ row.title }}</p>
        <p v-if="row.subtitle" class="text-xs text-neutral-500">{{ row.subtitle }}</p>
      </template>
      <template #cell-level="{ row }">
        <BaseBadge :variant="levelBadgeVariant[row.level as ProgramLevel]">
          {{ t(`admin.programs.level${row.level.charAt(0).toUpperCase()}${row.level.slice(1)}`) }}
        </BaseBadge>
      </template>
      <template #cell-is_featured="{ row }">
        <span v-if="row.is_featured" class="text-sm font-medium text-secondary-700">{{ t('common.yes') }}</span>
        <span v-else class="text-sm text-neutral-400">{{ t('common.no') }}</span>
      </template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="row.status === 'active' ? 'success' : 'neutral'">
          {{ row.status === 'active' ? t('admin.programs.statusActive') : t('admin.programs.statusInactive') }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <button type="button" class="text-sm font-medium text-secondary-700 hover:text-secondary-800" @click="openEdit(row)">
            {{ t('admin.programs.edit') }}
          </button>
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.programs.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" class="mt-4" @update:page="setPage" />

    <ProgramFormModal v-model="modalOpen" :program="editingProgram" @saved="fetch" />
  </div>
</template>
