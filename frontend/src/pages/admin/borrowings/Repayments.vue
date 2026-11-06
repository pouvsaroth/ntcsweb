<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BorrowingRepaymentModal from '@/components/admin/BorrowingRepaymentModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { borrowingsService, lendersService, type Borrowing, type BorrowingCurrency, type BorrowingRepayment, type Lender } from '@/services/borrowings'
import { expenseEntitiesService, type ExpenseEntity } from '@/services/expenseEntities'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'
import { formatDate } from '@/utils/date'

/**
 * Accounting > Borrowings > Repayments — what is due in the next 30 days
 * (late first), each with Record payment; below, every payment made, with
 * search and filters. Cards on a phone, a table from `sm` up.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => auth.can('borrowings.manage'))

const money = (value: number | string, currency: BorrowingCurrency) => formatMoney(Number(value), currency)

// --- Due ------------------------------------------------------------------------------------

const due = ref<Borrowing[]>([])
const dueLoading = ref(true)
const dueError = ref<string | null>(null)

async function loadDue() {
  dueLoading.value = true
  dueError.value = null
  try {
    due.value = await borrowingsService.due(30)
  } catch (e) {
    dueError.value = e instanceof ApiRequestError ? e.message : t('admin.borrowings.loadFailed')
  } finally {
    dueLoading.value = false
  }
}

const payOpen = ref(false)
const paying = ref<Borrowing | null>(null)
function pay(loan: Borrowing) {
  paying.value = loan
  payOpen.value = true
}
async function onPaid() {
  await Promise.all([loadDue(), fetch()])
}

// --- History --------------------------------------------------------------------------------

const { items, meta, loading, error, setPage, setSearch, setFilter, fetch } = usePaginatedResource<BorrowingRepayment>((query) => borrowingsService.repayments(query))

const lenders = ref<Lender[]>([])
const entities = ref<ExpenseEntity[]>([])
const lenderOptions = computed(() => lenders.value.map((l) => ({ value: String(l.id), label: l.name })))
const entityOptions = computed(() => entities.value.map((e) => ({ value: String(e.id), label: e.name })))
const lenderFilter = ref('')
const entityFilter = ref('')
const from = ref('')
const to = ref('')
watch(lenderFilter, (v) => setFilter('lender_id', v || undefined))
watch(entityFilter, (v) => setFilter('expense_entity_id', v || undefined))
watch(from, (v) => setFilter('from', v || undefined))
watch(to, (v) => setFilter('to', v || undefined))

const removeError = ref<string | null>(null)
async function remove(row: BorrowingRepayment) {
  if (!(await confirmDialog.confirm({ message: t('admin.borrowings.removeRepaymentConfirm'), danger: true }))) return
  removeError.value = null
  try {
    await borrowingsService.removeRepayment(row.id)
    await Promise.all([loadDue(), fetch()])
  } catch (e) {
    removeError.value = e instanceof ApiRequestError ? e.message : t('admin.borrowings.deleteFailed')
  }
}

onMounted(async () => {
  loadDue()
  fetch()
  ;[lenders.value, entities.value] = await Promise.all([lendersService.listAll().catch(() => []), expenseEntitiesService.listAll().catch(() => [])])
})
</script>

<template>
  <div>
    <!-- Due in the next 30 days -->
    <section class="mb-8">
      <h2 class="text-base font-semibold text-neutral-900">{{ t('admin.borrowings.dueSoon') }}</h2>
      <p class="mb-3 text-sm text-neutral-500">{{ t('admin.borrowings.dueSoonHint') }}</p>
      <BaseAlert v-if="dueError" variant="danger" class="mb-3">{{ dueError }}</BaseAlert>
      <div v-if="dueLoading" class="flex justify-center py-6"><BaseSpinner /></div>
      <p v-else-if="due.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-6 text-center text-sm text-neutral-500">{{ t('admin.borrowings.nothingDue') }}</p>
      <div v-else class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        <div
          v-for="loan in due"
          :key="loan.id"
          class="rounded-[--radius-card] border bg-white p-3 shadow-[--shadow-card]"
          :class="loan.next_payment?.late ? 'border-danger-200' : 'border-neutral-200'"
        >
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate text-sm font-semibold text-neutral-800">{{ loan.lender?.name ?? '—' }}</p>
              <p class="text-xs text-neutral-500">{{ t('admin.borrowings.forName', { name: loan.borrowed_for?.name ?? '—' }) }}</p>
            </div>
            <BaseBadge v-if="loan.next_payment?.late" variant="danger">{{ t('admin.borrowings.late') }}</BaseBadge>
          </div>
          <div class="mt-2 flex items-end justify-between gap-2">
            <div v-if="loan.flexible">
              <p class="text-lg font-semibold text-neutral-900">{{ money(loan.outstanding, loan.currency) }}</p>
              <p class="text-xs text-neutral-500">{{ t('admin.borrowings.outstanding') }} · {{ t('admin.borrowings.flexible') }}</p>
            </div>
            <div v-else>
              <p class="text-lg font-semibold text-neutral-900">{{ money((loan.next_payment?.due_principal ?? 0) + (loan.next_payment?.interest ?? 0), loan.currency) }}</p>
              <p class="text-xs text-neutral-500">
                {{ t('admin.borrowings.monthNo', { no: loan.next_payment?.no }) }} · {{ formatDate(loan.next_payment?.due_on) }}
                <template v-if="loan.late_count > 1"> · {{ t('admin.borrowings.lateCount', { count: loan.late_count }) }}</template>
              </p>
            </div>
            <BaseButton v-if="canManage" size="sm" @click="pay(loan)">{{ t('admin.borrowings.recordPayment') }}</BaseButton>
          </div>
        </div>
      </div>
    </section>

    <!-- Payments made -->
    <section>
      <h2 class="mb-3 text-base font-semibold text-neutral-900">{{ t('admin.borrowings.history') }}</h2>
      <div class="mb-4 flex flex-wrap items-end gap-3">
        <input
          type="search"
          :placeholder="t('admin.borrowings.searchPlaceholder')"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200 sm:max-w-xs"
          @input="setSearch(($event.target as HTMLInputElement).value)"
        />
        <SearchableSelect v-model="lenderFilter" class="w-full sm:w-48" :options="lenderOptions" :placeholder="t('admin.borrowings.allLenders')" />
        <SearchableSelect v-model="entityFilter" class="w-full sm:w-48" :options="entityOptions" :placeholder="t('admin.borrowings.allBorrowedFor')" />
        <div class="grid w-full grid-cols-2 gap-3 sm:w-auto">
          <BaseInput v-model="from" type="date" :label="t('admin.borrowings.from')" />
          <BaseInput v-model="to" type="date" :label="t('admin.borrowings.to')" />
        </div>
      </div>

      <BaseAlert v-if="error || removeError" variant="danger" class="mb-4">{{ error || removeError }}</BaseAlert>

      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('admin.borrowings.noRepayments') }}</p>
      <template v-else>
        <!-- Cards on small screens (below sm) instead of the table. -->
        <div class="space-y-2 sm:hidden">
          <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-neutral-800">{{ row.loan?.lender?.name ?? '—' }}</p>
                <p class="text-xs text-neutral-500">{{ formatDate(row.paid_on) }} · {{ t('admin.borrowings.forName', { name: row.loan?.borrowed_for?.name ?? '—' }) }}</p>
              </div>
              <p class="font-semibold text-neutral-900">{{ money(row.amount, row.loan?.currency ?? 'USD') }}</p>
            </div>
            <div class="mt-1 flex items-center justify-between gap-2">
              <p class="text-xs text-neutral-500">
                {{ t('admin.borrowings.principal') }} {{ money(row.principal, row.loan?.currency ?? 'USD') }} · {{ t('admin.borrowings.interest') }} {{ money(row.interest, row.loan?.currency ?? 'USD') }}
              </p>
              <button v-if="canManage" type="button" class="text-sm font-medium text-danger-600" @click="remove(row)">{{ t('admin.borrowings.delete') }}</button>
            </div>
          </div>
        </div>

        <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block">
          <table class="w-full text-left text-sm">
            <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-500">
              <tr>
                <th class="px-4 py-3 font-medium">{{ t('admin.borrowings.paidOn') }}</th>
                <th class="px-4 py-3 font-medium">{{ t('admin.borrowings.lender') }}</th>
                <th class="px-4 py-3 font-medium">{{ t('admin.borrowings.borrowedFor') }}</th>
                <th class="px-4 py-3 text-right font-medium">{{ t('admin.borrowings.principal') }}</th>
                <th class="px-4 py-3 text-right font-medium">{{ t('admin.borrowings.interest') }}</th>
                <th class="px-4 py-3 text-right font-medium">{{ t('admin.borrowings.payTotal') }}</th>
                <th class="px-4 py-3 font-medium">{{ t('admin.borrowings.paidFrom') }}</th>
                <th v-if="canManage" class="px-4 py-3" />
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="row in items" :key="row.id">
                <td class="px-4 py-3">{{ formatDate(row.paid_on) }}</td>
                <td class="px-4 py-3">
                  <p class="font-medium text-neutral-900">{{ row.loan?.lender?.name ?? '—' }}</p>
                  <p v-if="row.note || row.loan?.reference" class="text-xs text-neutral-500">{{ [row.loan?.reference, row.note].filter(Boolean).join(' · ') }}</p>
                </td>
                <td class="px-4 py-3 text-neutral-700">{{ row.loan?.borrowed_for?.name ?? '—' }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ money(row.principal, row.loan?.currency ?? 'USD') }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ money(row.interest, row.loan?.currency ?? 'USD') }}</td>
                <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ money(row.amount, row.loan?.currency ?? 'USD') }}</td>
                <td class="px-4 py-3 text-neutral-700">{{ row.cash_account?.name ?? '—' }}</td>
                <td v-if="canManage" class="px-4 py-3 text-right">
                  <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">{{ t('admin.borrowings.delete') }}</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>

      <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />
    </section>

    <BorrowingRepaymentModal v-model="payOpen" :loan="paying" @saved="onPaid" />
  </div>
</template>
