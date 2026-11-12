<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { expenseEntitiesService, type ExpenseEntity } from '@/services/expenseEntities'
import PayExpenseModal from '@/components/admin/PayExpenseModal.vue'
import { expensesService, expenseStatuses, type BulkExpenseResult, type Expense, type ExpenseStatus } from '@/services/expenses'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'
import { formatDate, formatMonth } from '@/utils/date'

const { t } = useI18n()
// After Add many: how many were saved.
const addedMany = Number(useRoute().query.added) || 0
const auth = useAuthStore()

const { items, meta, loading, error, setPage, setSort, sort, setSearch, setFilter, fetch } = usePaginatedResource<Expense>(
  (query) => expensesService.list(query),
)

const selectedStatus = ref('')

// Tick several, then Approve selected / Pay selected (each one is done on its own; the rest are listed with why).
const canApprove = computed(() => auth.can('expense.approve'))
const canPay = computed(() => auth.can('expense.pay'))
const canBulk = computed(() => canApprove.value || canPay.value)
const selected = ref<number[]>([])
const bulkBusy = ref(false)
const bulkMessage = ref<string | null>(null)
const bulkSkipped = ref<BulkExpenseResult['skipped']>([])
const payOpen = ref(false)
const payError = ref<string | null>(null)
const confirmDialog = useConfirmDialogStore()

function toggle(id: number, checked: boolean) {
  selected.value = checked ? [...new Set([...selected.value, id])] : selected.value.filter((x) => x !== id)
}
/** Quick picks on this page: every approved one (to pay) or every pending one (to approve). */
function pickStatus(status: ExpenseStatus) {
  selected.value = [...new Set([...selected.value, ...items.value.filter((e) => e.status === status).map((e) => e.id)])]
}

function showResult(result: BulkExpenseResult, doneKey: string) {
  bulkMessage.value = t(doneKey, { n: result.done.length })
  bulkSkipped.value = result.skipped
  selected.value = selected.value.filter((id) => !result.done.includes(id))
}

async function approveSelected() {
  if (!(await confirmDialog.confirm({ message: t('admin.expenses.bulk.approveConfirm', { n: selected.value.length }) }))) return
  bulkBusy.value = true
  bulkMessage.value = null
  try {
    showResult(await expensesService.bulkApprove(selected.value), 'admin.expenses.bulk.approved')
    await fetch()
  } catch (e) {
    bulkMessage.value = null
    bulkSkipped.value = []
    payError.value = null
    error.value = e instanceof ApiRequestError ? e.message : t('admin.expenses.actionFailed')
  } finally {
    bulkBusy.value = false
  }
}

async function paySelected(cashAccountId: number, paidDate: string) {
  bulkBusy.value = true
  payError.value = null
  bulkMessage.value = null
  try {
    showResult(await expensesService.bulkPay(selected.value, cashAccountId, paidDate), 'admin.expenses.bulk.paid')
    payOpen.value = false
    await fetch()
  } catch (e) {
    payError.value = e instanceof ApiRequestError ? e.message : t('admin.expenses.actionFailed')
  } finally {
    bulkBusy.value = false
  }
}

function statusKey(status: ExpenseStatus): string {
  return status
    .toLowerCase()
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('')
}

const statusVariant: Record<ExpenseStatus, 'neutral' | 'warning' | 'success' | 'danger' | 'primary'> = {
  DRAFT: 'neutral',
  PENDING_APPROVAL: 'warning',
  APPROVED: 'primary',
  PAID: 'success',
  REJECTED: 'danger',
  CANCELLED: 'neutral',
}

const statusFilterOptions = computed(() => expenseStatuses.map((status) => ({ value: status, label: t(`admin.expenses.status${statusKey(status)}`) })))

const selectedMonth = ref('')
function onMonthFilterChange(value: string) {
  selectedMonth.value = value
  setFilter('for_month', value || undefined)
}

// Filter by who it is for (Accounting > Expense for).
const entities = ref<ExpenseEntity[]>([])
const entityFilterOptions = computed(() => entities.value.map((e) => ({ value: String(e.id), label: e.name })))
const selectedEntity = ref('')
function onEntityFilterChange(value: string) {
  selectedEntity.value = value
  setFilter('expense_entity_id', value || undefined)
}

function onStatusFilterChange(value: string) {
  selectedStatus.value = value
  setFilter('status', value || undefined)
}

const columns = [
  { key: 'expense_date', label: t('admin.expenses.columnDate'), sortable: true },
  { key: 'for_month', label: t('admin.expenses.forMonth'), sortable: true },
  { key: 'expense_number', label: t('admin.expenses.columnNumber') },
  { key: 'expense_entity', label: t('admin.expenses.expenseFor') },
  { key: 'account', label: t('admin.expenses.columnAccount') },
  { key: 'vendor', label: t('admin.expenses.columnVendor') },
  { key: 'amount', label: t('admin.expenses.columnAmount'), sortable: true, align: 'text-right' },
  { key: 'status', label: t('admin.expenses.columnStatus') },
]

onMounted(() => {
  fetch()
  expenseEntitiesService
    .listAll()
    .then((list) => (entities.value = list))
    .catch(() => {})
})
</script>

<template>
  <div>
    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="addedMany" variant="success" class="mb-4">{{ t('admin.expenses.batch.saved', { n: addedMany }) }}</BaseAlert>
    <BaseAlert v-if="bulkMessage" :variant="bulkSkipped.length ? 'warning' : 'success'" class="mb-4">
      <p>{{ bulkMessage }}</p>
      <template v-if="bulkSkipped.length">
        <p class="mt-1 font-medium">{{ t('admin.expenses.bulk.skipped', { n: bulkSkipped.length }) }}</p>
        <ul class="mt-0.5 list-disc pl-5">
          <li v-for="s in bulkSkipped" :key="s.id">{{ s.expense_number }} — {{ s.reason }}</li>
        </ul>
      </template>
    </BaseAlert>

    <div class="mb-4 grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-5">
      <input
        type="search"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        :placeholder="t('admin.expenses.searchPlaceholder')"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseSelect
        :model-value="selectedStatus"
        :options="statusFilterOptions"
        :placeholder="t('admin.expenses.filterAllStatuses')"
        @update:model-value="onStatusFilterChange"
      />
      <BaseSelect
        :model-value="selectedEntity"
        :options="entityFilterOptions"
        :placeholder="t('admin.expenses.filterAllExpenseFor')"
        @update:model-value="onEntityFilterChange"
      />
      <BaseInput :model-value="selectedMonth" type="month" :label="t('admin.expenses.filterForMonth')" @update:model-value="onMonthFilterChange" />
      <BaseButton v-if="auth.can('expense.create')" variant="outline" to="/admin/expenses/new-many">{{ t('admin.expenses.batch.button') }}</BaseButton>
      <BaseButton v-if="auth.can('expense.create')" to="/admin/expenses/new">{{ t('admin.expenses.addExpense') }}</BaseButton>
    </div>

    <div v-if="canBulk" class="sticky top-0 z-10 mb-3 flex flex-wrap items-center gap-2 rounded-lg border border-neutral-200 bg-white/95 px-3 py-2 text-sm shadow-sm backdrop-blur">
      <template v-if="selected.length">
        <span class="font-medium text-neutral-800">{{ t('admin.expenses.bulk.selected', { n: selected.length }) }}</span>
        <BaseButton v-if="canApprove" size="sm" variant="outline" :loading="bulkBusy && !payOpen" @click="approveSelected">{{ t('admin.expenses.bulk.approve') }}</BaseButton>
        <BaseButton v-if="canPay" size="sm" @click="payOpen = true">{{ t('admin.expenses.bulk.pay') }}</BaseButton>
        <button type="button" class="text-neutral-500 hover:text-neutral-800" @click="selected = []">{{ t('admin.expenses.bulk.clear') }}</button>
      </template>
      <template v-else>
        <span class="text-neutral-500">{{ t('admin.expenses.bulk.hint') }}</span>
        <button v-if="canPay" type="button" class="font-medium text-primary-600 hover:text-primary-700" @click="pickStatus('APPROVED')">{{ t('admin.expenses.bulk.pickApproved') }}</button>
        <button v-if="canApprove" type="button" class="font-medium text-primary-600 hover:text-primary-700" @click="pickStatus('PENDING_APPROVAL')">{{ t('admin.expenses.bulk.pickPending') }}</button>
      </template>
    </div>

    <!-- Cards on small screens — below sm: this replaces the DataTable
         entirely. The whole card opens the expense (Approve / Reject / Pay
         live on its detail page). -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.expenses.emptyMessage') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="flex items-stretch gap-2">
        <label v-if="canBulk" class="flex shrink-0 items-center px-1">
          <input
            type="checkbox"
            class="h-5 w-5 rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
            :checked="selected.includes(row.id)"
            :aria-label="row.expense_number"
            @change="toggle(row.id, ($event.target as HTMLInputElement).checked)"
          />
        </label>
        <RouterLink
          :to="`/admin/expenses/${row.id}`"
          class="block min-w-0 flex-1 rounded-[--radius-card] border bg-white p-3 shadow-[--shadow-card] active:bg-neutral-50"
          :class="selected.includes(row.id) ? 'border-primary-400' : 'border-neutral-200'"
        >
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate font-medium text-primary-700">{{ row.expense_number }}</p>
              <p class="text-xs text-neutral-500">{{ formatDate(row.expense_date) }} · {{ t('admin.expenses.forMonth') }} {{ formatMonth(row.for_month) }}</p>
            </div>
            <BaseBadge :variant="statusVariant[row.status]">{{ t(`admin.expenses.status${statusKey(row.status)}`) }}</BaseBadge>
          </div>
          <p class="mt-2 truncate text-sm text-neutral-700">{{ row.account.code }} — {{ row.account.name }}</p>
          <p v-if="row.expense_entity" class="truncate text-xs text-neutral-500">{{ t('admin.expenses.expenseFor') }}: {{ row.expense_entity.name }}</p>
          <div class="mt-1 flex items-center justify-between gap-2">
            <p class="truncate text-xs text-neutral-500">{{ row.vendor ?? '—' }}</p>
            <p class="shrink-0 text-base font-semibold text-neutral-900">{{ formatMoney(row.amount, row.currency) }}</p>
          </div>
        </RouterLink>
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
      :empty-message="t('admin.expenses.emptyMessage')"
      :selectable="canBulk"
      :selected="selected"
      @update:selected="selected = $event as number[]"
      @sort="(col) => setSort(sort === col ? `-${col}` : col)"
    >
      <template #cell-expense_number="{ row }">
        <RouterLink :to="`/admin/expenses/${row.id}`" class="font-medium text-primary-700 hover:underline">{{ row.expense_number }}</RouterLink>
      </template>
      <template #cell-expense_date="{ row }">{{ formatDate(row.expense_date) }}</template>
      <template #cell-for_month="{ row }">{{ formatMonth(row.for_month) }}</template>
      <template #cell-expense_entity="{ row }">{{ row.expense_entity?.name ?? '—' }}</template>
      <template #cell-account="{ row }">{{ row.account.code }} — {{ row.account.name }}</template>
      <template #cell-vendor="{ row }">{{ row.vendor ?? '—' }}</template>
      <template #cell-amount="{ row }">{{ formatMoney(row.amount, row.currency) }}</template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="statusVariant[row.status]">{{ t(`admin.expenses.status${statusKey(row.status)}`) }}</BaseBadge>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <PayExpenseModal v-model="payOpen" :submitting="bulkBusy" :error="payError" @confirm="paySelected" />
  </div>
</template>
