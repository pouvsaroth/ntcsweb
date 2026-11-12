<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import ExpenseSupportFormModal from '@/components/admin/ExpenseSupportFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { expenseEntitiesService, type ExpenseEntity } from '@/services/expenseEntities'
import { expenseSupportsService, type ExpenseSupport } from '@/services/expenseSupports'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'
import { formatDate } from '@/utils/date'

/** Accounting > Expenses > Support: who gave money or food towards an Expense for (e.g. visitors at the hospital). */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const from = ref('')
const to = ref('')
const { items, meta, loading, error, setPage, setSearch, setFilter, fetch } = usePaginatedResource<ExpenseSupport>((query) =>
  expenseSupportsService.list(query, { from: from.value, to: to.value }),
)

const canCreate = computed(() => auth.can('expense-supports.create'))
const canUpdate = computed(() => auth.can('expense-supports.update'))
const canDelete = computed(() => auth.can('expense-supports.delete'))
const canEdit = computed(() => canUpdate.value || canDelete.value)

const entities = ref<ExpenseEntity[]>([])
const entityId = ref('')
const kind = ref('')
const entityFilterOptions = computed(() => [{ value: '', label: t('admin.expenses.filterAllExpenseFor') }, ...entities.value.map((e) => ({ value: String(e.id), label: e.name }))])
const kindOptions = computed(() => [
  { value: '', label: t('admin.expenseSupports.allKinds') },
  { value: 'money', label: t('admin.expenseSupports.kinds.money') },
  { value: 'food', label: t('admin.expenseSupports.kinds.food') },
])

function onEntity(value: string) {
  entityId.value = value
  setFilter('expense_entity_id', value || undefined)
}
function onKind(value: string) {
  kind.value = value
  setFilter('kind', value || undefined)
}
function onRange(which: 'from' | 'to', value: string) {
  if (which === 'from') from.value = value
  else to.value = value
  setPage(1)
}

const columns = computed(() => [
  { key: 'support_date', label: t('admin.expenseSupports.supportDate') },
  { key: 'name', label: t('admin.expenseSupports.name') },
  { key: 'kind', label: t('admin.expenseSupports.given') },
  { key: 'amount', label: t('admin.expenseSupports.amount'), align: 'text-right' },
  { key: 'expense_entity', label: t('admin.expenses.expenseFor') },
  ...(canEdit.value ? [{ key: 'actions', label: '', align: 'text-right' }] : []),
])

const money = (row: ExpenseSupport) => (row.amount != null && row.currency ? formatMoney(row.amount, row.currency) : '—')
const relationship = (row: ExpenseSupport) => (row.relationship ? t(`admin.expenseSupports.relationships.${row.relationship}`) : '')

const modalOpen = ref(false)
const editing = ref<ExpenseSupport | null>(null)
const deleteError = ref<string | null>(null)

function openCreate() {
  editing.value = null
  modalOpen.value = true
}
function openEdit(row: ExpenseSupport) {
  editing.value = row
  modalOpen.value = true
}

async function remove(row: ExpenseSupport) {
  if (!(await confirmDialog.confirm({ message: t('admin.expenseSupports.deleteConfirm', { name: row.name }), danger: true }))) return
  deleteError.value = null
  try {
    await expenseSupportsService.remove(row.id)
    await fetch()
  } catch (e) {
    deleteError.value = e instanceof ApiRequestError ? e.message : t('admin.expenseSupports.deleteFailed')
  }
}

onMounted(async () => {
  void fetch()
  entities.value = await expenseEntitiesService.listAll().catch(() => [])
})
</script>

<template>
  <div>
    <p class="mb-4 text-sm text-neutral-500">{{ t('admin.expenseSupports.pageSubtitle') }}</p>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_12rem_10rem_10rem_10rem_auto] lg:items-end">
      <input
        type="search"
        :placeholder="t('admin.expenseSupports.searchPlaceholder')"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseSelect :model-value="entityId" :options="entityFilterOptions" @update:model-value="onEntity" />
      <BaseSelect :model-value="kind" :options="kindOptions" @update:model-value="onKind" />
      <BaseInput :model-value="from" type="date" :label="t('admin.expenseSupports.from')" @update:model-value="onRange('from', $event)" />
      <BaseInput :model-value="to" type="date" :label="t('admin.expenseSupports.to')" @update:model-value="onRange('to', $event)" />
      <BaseButton v-if="canCreate" class="shrink-0" @click="openCreate">{{ t('admin.expenseSupports.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error || deleteError" variant="danger" class="mb-4">{{ error || deleteError }}</BaseAlert>

    <!-- Cards on small screens (below sm) instead of the table. -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.expenseSupports.emptyMessage') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate font-medium text-neutral-900">{{ row.name }}</p>
              <p class="text-xs text-neutral-500">
                {{ formatDate(row.support_date) }}{{ relationship(row) ? ` · ${relationship(row)}` : '' }}{{ row.phone ? ` · ${row.phone}` : '' }}
              </p>
            </div>
            <div class="text-right">
              <BaseBadge :variant="row.kind === 'money' ? 'success' : 'warning'">{{ t(`admin.expenseSupports.kinds.${row.kind}`) }}</BaseBadge>
              <p class="mt-1 text-sm font-semibold whitespace-nowrap text-neutral-900 tabular-nums">{{ money(row) }}</p>
            </div>
          </div>
          <p v-if="row.items" class="mt-1 text-sm text-neutral-700">{{ row.items }}</p>
          <p v-if="row.note" class="mt-1 text-xs text-neutral-500">{{ row.note }}</p>
          <div class="mt-2 flex items-center justify-between gap-2">
            <p class="truncate text-xs text-neutral-500">{{ row.expense_entity?.name ?? '—' }}</p>
            <div v-if="canEdit" class="flex items-center gap-3">
              <EditIconButton v-if="canUpdate" @click="openEdit(row)" />
              <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
                {{ t('admin.expenseSupports.delete') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <DataTable class="hidden sm:block" :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.expenseSupports.emptyMessage')">
      <template #cell-support_date="{ row }"><span class="whitespace-nowrap">{{ formatDate(row.support_date) }}</span></template>
      <template #cell-name="{ row }">
        <p class="font-medium text-neutral-900">{{ row.name }}</p>
        <p v-if="relationship(row as ExpenseSupport) || row.phone" class="text-xs text-neutral-500">
          {{ [relationship(row as ExpenseSupport), row.phone].filter(Boolean).join(' · ') }}
        </p>
        <p v-if="row.note" class="max-w-xs text-xs text-neutral-500">{{ row.note }}</p>
      </template>
      <template #cell-kind="{ row }">
        <BaseBadge :variant="row.kind === 'money' ? 'success' : 'warning'">{{ t(`admin.expenseSupports.kinds.${row.kind}`) }}</BaseBadge>
        <p v-if="row.items" class="mt-0.5 max-w-xs text-xs text-neutral-600">{{ row.items }}</p>
      </template>
      <template #cell-amount="{ row }"><span class="whitespace-nowrap tabular-nums">{{ money(row as ExpenseSupport) }}</span></template>
      <template #cell-expense_entity="{ row }">{{ row.expense_entity?.name ?? '—' }}</template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton v-if="canUpdate" @click="openEdit(row as ExpenseSupport)" />
          <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row as ExpenseSupport)">
            {{ t('admin.expenseSupports.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <ExpenseSupportFormModal v-model="modalOpen" :support="editing" :entities="entities" :default-entity-id="entityId ? Number(entityId) : null" @saved="fetch()" />
  </div>
</template>
