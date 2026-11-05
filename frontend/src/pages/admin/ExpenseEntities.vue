<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import ExpenseEntityFormModal from '@/components/admin/ExpenseEntityFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { expenseEntitiesService, type ExpenseEntity } from '@/services/expenseEntities'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

// Accounting > Expense for: who an expense is for (Newtech, Flexi, Family...).
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<ExpenseEntity>((query) => expenseEntitiesService.list(query))

const canCreate = computed(() => auth.can('expense.create'))
const canEdit = computed(() => auth.can('expense.update'))

const columns = computed(() => [
  { key: 'name', label: t('admin.expenseEntities.columnName') },
  { key: 'description', label: t('admin.expenseEntities.columnDescription') },
  { key: 'expenses_count', label: t('admin.expenseEntities.columnExpenses'), align: 'text-right' },
  { key: 'is_active', label: t('admin.expenseEntities.columnStatus') },
  ...(canEdit.value ? [{ key: 'actions', label: t('admin.expenseEntities.columnActions'), align: 'text-right' }] : []),
])

const modalOpen = ref(false)
const editing = ref<ExpenseEntity | null>(null)
const deleteError = ref<string | null>(null)

function openCreate() {
  editing.value = null
  modalOpen.value = true
}

function openEdit(entity: ExpenseEntity) {
  editing.value = entity
  modalOpen.value = true
}

async function remove(entity: ExpenseEntity) {
  if (!(await confirmDialog.confirm({ message: t('admin.expenseEntities.deleteConfirm'), danger: true }))) return
  deleteError.value = null

  try {
    await expenseEntitiesService.remove(entity.id)
    await fetch()
  } catch (e) {
    deleteError.value = e instanceof ApiRequestError ? e.message : t('admin.expenseEntities.deleteFailed')
  }
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <p class="mb-4 text-sm text-neutral-500">{{ t('admin.expenseEntities.pageSubtitle') }}</p>

    <div class="mb-6 flex items-center justify-between gap-3">
      <input
        type="search"
        :placeholder="t('common.searchPlaceholder')"
        class="block w-full max-w-sm rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseButton v-if="canCreate" class="shrink-0" @click="openCreate">{{ t('admin.expenseEntities.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error || deleteError" variant="danger" class="mb-4">{{ error || deleteError }}</BaseAlert>

    <!-- Cards on small screens (below sm) instead of the table. -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.expenseEntities.emptyMessage') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate font-medium text-neutral-900">{{ row.name }}</p>
              <p v-if="row.description" class="mt-0.5 text-xs text-neutral-500">{{ row.description }}</p>
            </div>
            <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">
              {{ row.is_active ? t('admin.expenseEntities.statusActive') : t('admin.expenseEntities.statusInactive') }}
            </BaseBadge>
          </div>
          <div class="mt-2 flex items-center justify-between gap-2">
            <p class="text-xs text-neutral-500">{{ t('admin.expenseEntities.columnExpenses') }}: {{ row.expenses_count ?? 0 }}</p>
            <div v-if="canEdit" class="flex items-center gap-3">
              <EditIconButton @click="openEdit(row)" />
              <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
                {{ t('admin.expenseEntities.delete') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <DataTable class="hidden sm:block" :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.expenseEntities.emptyMessage')">
      <template #cell-description="{ row }">{{ row.description ?? '—' }}</template>
      <template #cell-expenses_count="{ row }">{{ row.expenses_count ?? 0 }}</template>
      <template #cell-is_active="{ row }">
        <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">
          {{ row.is_active ? t('admin.expenseEntities.statusActive') : t('admin.expenseEntities.statusInactive') }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton @click="openEdit(row)" />
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.expenseEntities.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <ExpenseEntityFormModal v-model="modalOpen" :entity="editing" @saved="fetch()" />
  </div>
</template>
