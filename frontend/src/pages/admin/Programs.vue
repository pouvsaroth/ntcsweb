<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import ProgramFormModal from '@/components/admin/ProgramFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { programsService, type Program, type ProgramLevel } from '@/services/programs'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { moneyOrDash } from '@/utils/currency'

const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const { items, meta, loading, error, setPage, setSort, sort, fetch } = usePaginatedResource<Program>((query) =>
  programsService.list(query),
)

const columns = [
  { key: 'image_url', label: t('admin.programs.columnPreview') },
  { key: 'title', label: t('admin.programs.columnTitle'), sortable: true },
  { key: 'category', label: t('admin.programs.columnCategory') },
  { key: 'level', label: t('admin.programs.columnLevel') },
  // USD and KHR at today's rate.
  { key: 'fee', label: `${t('admin.programs.columnFee')} (USD)`, align: 'text-right' },
  { key: 'fee_khr', label: `${t('admin.programs.columnFee')} (KHR)`, align: 'text-right' },
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
  if (!(await confirmDialog.confirm({ message: t('admin.programs.deleteConfirm'), danger: true }))) return
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
      </div>
      <BaseButton v-if="auth.can('programs.create')" @click="openCreate">{{ t('admin.programs.addProgram') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <!-- Cards on small screens (below sm) -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('admin.programs.emptyMessage') }}</p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate font-medium text-neutral-900">{{ row.title }}</p>
              <p v-if="row.subtitle" class="truncate text-xs text-neutral-500">{{ row.subtitle }}</p>
            </div>
            <div class="shrink-0 text-right font-semibold tabular-nums text-neutral-900">{{ moneyOrDash(row.fee_usd, 'USD') }}<span class="block text-xs font-normal text-neutral-500">{{ moneyOrDash(row.fee_khr, 'KHR') }}</span></div>
          </div>
          <div class="mt-2 flex items-center justify-between gap-2">
            <BaseBadge :variant="row.status === 'active' ? 'success' : 'neutral'">{{ row.status === 'active' ? t('admin.programs.statusActive') : t('admin.programs.statusInactive') }}</BaseBadge>
            <div class="flex items-center gap-2">
              <EditIconButton v-if="auth.can('programs.update')" @click="openEdit(row)" />
              <button v-if="auth.can('programs.delete')" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">{{ t('admin.programs.delete') }}</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <DataTable
      class="hidden sm:block"
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
      <template #cell-fee="{ row }"><span class="whitespace-nowrap">{{ moneyOrDash(row.fee_usd, 'USD') }}</span></template>
      <template #cell-fee_khr="{ row }"><span class="whitespace-nowrap">{{ moneyOrDash(row.fee_khr, 'KHR') }}</span></template>
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
          <EditIconButton v-if="auth.can('programs.update')" @click="openEdit(row)" />
          <button v-if="auth.can('programs.delete')" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.programs.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <ProgramFormModal v-model="modalOpen" :program="editingProgram" @saved="fetch" />
  </div>
</template>
