<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import BorrowingRepaymentModal from '@/components/admin/BorrowingRepaymentModal.vue'
import ExpenseEntityFormModal from '@/components/admin/ExpenseEntityFormModal.vue'
import LenderFormModal from '@/components/admin/LenderFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { accountsService, type Account } from '@/services/accounting'
import {
  borrowingsService,
  lendersService,
  type Borrowing,
  type BorrowingCurrency,
  type BorrowingInput,
  type BorrowingRepayment,
  type BorrowingStatus,
  type InterestPeriod,
  type InterestType,
  type Lender,
  type ScheduleRow,
  type ScheduleTotals,
} from '@/services/borrowings'
import { expenseEntitiesService, type ExpenseEntity } from '@/services/expenseEntities'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'
import { formatDate } from '@/utils/date'

/**
 * Accounting > Borrowings > Loans — money borrowed from companies and
 * people, each for one "Borrowed for" (the Expense for list: Newtech, Flexi,
 * Family...). The monthly plan is worked out by the server from the terms,
 * shown live while typing. Cards on a phone, a table from `sm` up.
 */
const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => auth.can('borrowings.manage'))

const money = (value: number | string, currency: BorrowingCurrency) => formatMoney(Number(value), currency)

function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

/** Same day next month — when the first payment usually falls. */
function monthAfter(date: string): string {
  const [y, m, d] = date.split('-').map(Number) as [number, number, number]
  const last = new Date(y, m + 1, 0).getDate()
  return `${m === 12 ? y + 1 : y}-${String((m % 12) + 1).padStart(2, '0')}-${String(Math.min(d, last)).padStart(2, '0')}`
}

// --- Lookups -------------------------------------------------------------------------------

const lenders = ref<Lender[]>([])
const entities = ref<ExpenseEntity[]>([])
const cashAccounts = ref<Account[]>([])
const lenderOptions = computed(() => lenders.value.map((l) => ({ value: String(l.id), label: l.name, hint: t(`admin.borrowings.lenderType.${l.type}`) })))
const entityOptions = computed(() => entities.value.map((e) => ({ value: String(e.id), label: e.name })))
const cashOptions = computed(() => cashAccounts.value.map((a) => ({ value: String(a.id), label: `${a.code} — ${a.name}` })))

async function loadLookups() {
  ;[lenders.value, entities.value, cashAccounts.value] = await Promise.all([
    lendersService.listAll().catch(() => []),
    expenseEntitiesService.listAll().catch(() => []),
    accountsService
      .listAll()
      .then((all) => all.filter((a) => a.is_bank_or_cash && a.is_active))
      .catch(() => []),
  ])
}

// --- List ----------------------------------------------------------------------------------

const { items, meta, loading, error, setPage, setSearch, setFilter, fetch } = usePaginatedResource<Borrowing>((query) => borrowingsService.list(query))

const statusFilter = ref<BorrowingStatus | ''>('active')
const lenderFilter = ref('')
const entityFilter = ref('')
watch(statusFilter, (v) => setFilter('status', v || undefined))
watch(lenderFilter, (v) => setFilter('lender_id', v || undefined))
watch(entityFilter, (v) => setFilter('expense_entity_id', v || undefined))

const statusOptions = computed(() => [
  { value: '', label: t('admin.borrowings.allStatuses') },
  { value: 'active', label: t('admin.borrowings.statuses.active') },
  { value: 'paid_off', label: t('admin.borrowings.statuses.paid_off') },
  { value: 'cancelled', label: t('admin.borrowings.statuses.cancelled') },
])
const statusVariant: Record<BorrowingStatus, 'primary' | 'success' | 'neutral'> = { active: 'primary', paid_off: 'success', cancelled: 'neutral' }

function interestLabel(loan: Pick<Borrowing, 'interest_type' | 'interest_rate' | 'interest_period'>): string {
  if (loan.interest_type === 'none') return t('admin.borrowings.interestTypes.none')
  return t('admin.borrowings.interestShort', {
    rate: Number(loan.interest_rate),
    period: t(`admin.borrowings.periods.${loan.interest_period}`),
    type: t(`admin.borrowings.interestTypes.${loan.interest_type}`),
  })
}

// --- New / edit ----------------------------------------------------------------------------

const formOpen = ref(false)
const editing = ref<Borrowing | null>(null)
const form = reactive({
  lender_id: '',
  expense_entity_id: '',
  reference: '',
  amount: '',
  currency: 'USD' as BorrowingCurrency,
  received_on: '',
  cash_account_id: '',
  interest_type: 'none' as InterestType,
  interest_rate: '',
  interest_period: 'month' as InterestPeriod,
  term_months: '',
  first_payment_on: '',
  purpose: '',
})
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)
const lenderModalOpen = ref(false)
const entityModalOpen = ref(false)

const currencyOptions = [
  { value: 'USD', label: 'USD ($)' },
  { value: 'KHR', label: 'KHR (៛)' },
]
const interestOptions = computed(() => (['none', 'flat', 'reducing'] as const).map((v) => ({ value: v, label: t(`admin.borrowings.interestTypes.${v}`) })))
const periodOptions = computed(() => (['month', 'year'] as const).map((v) => ({ value: v, label: t(`admin.borrowings.perPeriod.${v}`) })))
const interestHint = computed(() => t(`admin.borrowings.interestHints.${form.interest_type}`))
// Once a payment is recorded the amount and plan are locked (server-side too).
const locked = computed(() => (editing.value?.paid_principal ?? 0) > 0 || (editing.value?.paid_interest ?? 0) > 0)

async function openForm(loan: Borrowing | null) {
  editing.value = loan
  form.lender_id = loan?.lender ? String(loan.lender.id) : ''
  form.expense_entity_id = loan?.borrowed_for ? String(loan.borrowed_for.id) : ''
  form.reference = loan?.reference ?? ''
  form.amount = loan ? String(Number(loan.amount)) : ''
  form.currency = loan?.currency ?? ((auth.tenantDefaultCurrency as BorrowingCurrency | null) ?? 'USD')
  form.received_on = loan?.received_on ?? today()
  form.cash_account_id = loan?.cash_account ? String(loan.cash_account.id) : ''
  form.interest_type = loan?.interest_type ?? 'none'
  form.interest_rate = loan && loan.interest_type !== 'none' ? String(Number(loan.interest_rate)) : ''
  form.interest_period = loan?.interest_period ?? 'month'
  form.term_months = loan?.term_months ? String(loan.term_months) : ''
  form.first_payment_on = loan?.first_payment_on ?? ''
  form.purpose = loan?.purpose ?? ''
  errors.value = {}
  saveError.value = null
  formOpen.value = true
  if (lenders.value.length === 0 || entities.value.length === 0) await loadLookups()
  if (!loan && entities.value.length === 1) form.expense_entity_id = String(entities.value[0]!.id)
}

function onLenderSaved(lender: Lender) {
  if (!lender.is_active) return
  lenders.value = [...lenders.value, lender].sort((a, b) => a.name.localeCompare(b.name))
  form.lender_id = String(lender.id)
}

function onEntitySaved(entity: ExpenseEntity) {
  if (!entity.is_active) return
  entities.value = [...entities.value, entity].sort((a, b) => a.name.localeCompare(b.name))
  form.expense_entity_id = String(entity.id)
}

function input(): BorrowingInput {
  return {
    lender_id: form.lender_id ? Number(form.lender_id) : null,
    expense_entity_id: form.expense_entity_id ? Number(form.expense_entity_id) : null,
    reference: form.reference.trim() || null,
    amount: Number(form.amount || 0),
    currency: form.currency,
    received_on: form.received_on,
    cash_account_id: form.cash_account_id ? Number(form.cash_account_id) : null,
    interest_type: form.interest_type,
    interest_rate: form.interest_type === 'none' ? 0 : Number(form.interest_rate || 0),
    interest_period: form.interest_period,
    // No months: a flexible loan (any date, any amount, interest only).
    term_months: Number(form.term_months) > 0 ? Number(form.term_months) : null,
    first_payment_on: Number(form.term_months) > 0 ? form.first_payment_on || null : null,
    purpose: form.purpose.trim() || null,
  }
}

// Live plan while typing (debounced).
const preview = ref<{ schedule: ScheduleRow[]; totals: ScheduleTotals } | null>(null)
let previewTimer: ReturnType<typeof setTimeout> | undefined
watch(
  () => [formOpen.value, form.amount, form.currency, form.interest_type, form.interest_rate, form.interest_period, form.term_months, form.first_payment_on, form.received_on],
  () => {
    clearTimeout(previewTimer)
    const i = input()
    const months = i.term_months ?? 0
    // Without a first payment date the plan starts a month after receiving (as on the server).
    const first = i.first_payment_on || (form.received_on ? monthAfter(form.received_on) : '')
    const ready = formOpen.value && i.amount > 0 && months > 0 && first && (i.interest_type === 'none' || i.interest_rate > 0)
    if (!ready) {
      preview.value = null
      return
    }
    previewTimer = setTimeout(async () => {
      preview.value = await borrowingsService
        .preview({ amount: i.amount, currency: i.currency, interest_type: i.interest_type, interest_rate: i.interest_rate, interest_period: i.interest_period, term_months: months, first_payment_on: first })
        .catch(() => null)
    }, 300)
  },
)

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  try {
    const data = input()
    const payload = locked.value
      ? ({ lender_id: data.lender_id, expense_entity_id: data.expense_entity_id, reference: data.reference, purpose: data.purpose } as unknown as BorrowingInput)
      : data
    const saved = editing.value ? await borrowingsService.update(editing.value.id, payload) : await borrowingsService.create(payload)
    formOpen.value = false
    await fetch()
    detail.value = saved
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.borrowings.saveFailed')
  } finally {
    saving.value = false
  }
}

// --- One loan ------------------------------------------------------------------------------

const detail = ref<Borrowing | null>(null)
const detailLoading = ref(false)
const detailError = ref<string | null>(null)
const uploading = ref(false)

async function openDetail(loan: Borrowing) {
  detail.value = loan
  detailError.value = null
  detailLoading.value = true
  try {
    detail.value = await borrowingsService.get(loan.id)
  } catch (e) {
    detailError.value = e instanceof ApiRequestError ? e.message : t('admin.borrowings.loadFailed')
  } finally {
    detailLoading.value = false
  }
}

const payOpen = ref(false)
async function onPaid(loan: Borrowing) {
  detail.value = loan
  await fetch()
}

async function removeRepayment(row: BorrowingRepayment) {
  if (!detail.value || !(await confirmDialog.confirm({ message: t('admin.borrowings.removeRepaymentConfirm'), danger: true }))) return
  detailError.value = null
  try {
    detail.value = await borrowingsService.removeRepayment(row.id)
    await fetch()
  } catch (e) {
    detailError.value = e instanceof ApiRequestError ? e.message : t('admin.borrowings.deleteFailed')
  }
}

const hasPayments = (loan: Borrowing) => loan.paid_principal > 0 || loan.paid_interest > 0 || (loan.repayments?.length ?? 0) > 0

async function onContract(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  ;(event.target as HTMLInputElement).value = ''
  if (!file || !detail.value) return
  uploading.value = true
  detailError.value = null
  try {
    detail.value = await borrowingsService.uploadContract(detail.value.id, file)
    await fetch()
  } catch (e) {
    detailError.value = e instanceof ApiRequestError ? (e.errors?.file?.[0] ?? e.message) : t('admin.borrowings.saveFailed')
  } finally {
    uploading.value = false
  }
}

async function downloadContract(loan: Borrowing) {
  detailError.value = null
  try {
    await borrowingsService.downloadContract(loan)
  } catch {
    detailError.value = t('admin.borrowings.downloadFailed')
  }
}

async function cancelLoan(loan: Borrowing) {
  if (!(await confirmDialog.confirm({ message: t('admin.borrowings.cancelConfirm'), danger: true }))) return
  detailError.value = null
  try {
    detail.value = await borrowingsService.cancel(loan.id)
    await fetch()
  } catch (e) {
    detailError.value = e instanceof ApiRequestError ? e.message : t('admin.borrowings.saveFailed')
  }
}

async function removeLoan(loan: Borrowing) {
  if (!(await confirmDialog.confirm({ message: t('admin.borrowings.deleteConfirm'), danger: true }))) return
  detailError.value = null
  try {
    await borrowingsService.remove(loan.id)
    detail.value = null
    await fetch()
  } catch (e) {
    detailError.value = e instanceof ApiRequestError ? e.message : t('admin.borrowings.deleteFailed')
  }
}

onMounted(() => {
  setFilter('status', statusFilter.value || undefined)
  loadLookups()
})
</script>

<template>
  <div>
    <p class="mb-4 text-sm text-neutral-500">{{ t('admin.borrowings.subtitle') }}</p>

    <div class="mb-4 flex flex-wrap items-center gap-3">
      <input
        type="search"
        :placeholder="t('admin.borrowings.searchPlaceholder')"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200 sm:max-w-xs"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseSelect class="w-full sm:w-40" :model-value="statusFilter" :options="statusOptions" @update:model-value="statusFilter = $event as BorrowingStatus | ''" />
      <SearchableSelect v-model="lenderFilter" class="w-full sm:w-48" :options="lenderOptions" :placeholder="t('admin.borrowings.allLenders')" />
      <SearchableSelect v-model="entityFilter" class="w-full sm:w-48" :options="entityOptions" :placeholder="t('admin.borrowings.allBorrowedFor')" />
      <BaseButton v-if="canManage" class="w-full sm:ml-auto sm:w-auto" @click="openForm(null)">{{ t('admin.borrowings.addLoan') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
    <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('admin.borrowings.noLoans') }}</p>
    <template v-else>
      <!-- Cards on small screens (below sm) instead of the table. -->
      <div class="space-y-2 sm:hidden">
        <button
          v-for="loan in items"
          :key="loan.id"
          type="button"
          class="block w-full rounded-[--radius-card] border border-neutral-200 bg-white p-3 text-left shadow-[--shadow-card]"
          @click="openDetail(loan)"
        >
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate text-sm font-semibold text-neutral-800">{{ loan.lender?.name ?? '—' }}</p>
              <p class="text-xs text-neutral-500">{{ t('admin.borrowings.forName', { name: loan.borrowed_for?.name ?? '—' }) }} · {{ formatDate(loan.received_on) }}</p>
            </div>
            <BaseBadge v-if="loan.late_count" variant="danger">{{ t('admin.borrowings.lateCount', { count: loan.late_count }) }}</BaseBadge>
            <BaseBadge v-else :variant="statusVariant[loan.status]">{{ t(`admin.borrowings.statuses.${loan.status}`) }}</BaseBadge>
          </div>
          <p class="mt-1 text-sm font-semibold text-neutral-900">
            {{ money(loan.amount, loan.currency) }}
            <span v-if="loan.status === 'active'" class="font-normal text-neutral-500">· {{ t('admin.borrowings.outstanding') }} {{ money(loan.outstanding, loan.currency) }}</span>
          </p>
          <p class="text-xs text-neutral-500">
            <template v-if="loan.flexible">{{ t('admin.borrowings.flexible') }}</template>
            <template v-else>{{ t('admin.borrowings.monthlyOf', { amount: money(loan.monthly_payment, loan.currency), months: loan.term_months }) }}</template>
            <template v-if="loan.next_payment"> · {{ t('admin.borrowings.nextDue', { date: formatDate(loan.next_payment.due_on) }) }}</template>
          </p>
        </button>
      </div>

      <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block">
        <table class="w-full text-left text-sm">
          <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-500">
            <tr>
              <th class="px-4 py-3 font-medium">{{ t('admin.borrowings.lender') }}</th>
              <th class="px-4 py-3 font-medium">{{ t('admin.borrowings.borrowedFor') }}</th>
              <th class="px-4 py-3 text-right font-medium">{{ t('admin.borrowings.amount') }}</th>
              <th class="px-4 py-3 text-right font-medium">{{ t('admin.borrowings.outstanding') }}</th>
              <th class="px-4 py-3 font-medium">{{ t('admin.borrowings.interest') }}</th>
              <th class="px-4 py-3 text-right font-medium">{{ t('admin.borrowings.monthly') }}</th>
              <th class="px-4 py-3 font-medium">{{ t('admin.borrowings.nextPayment') }}</th>
              <th class="px-4 py-3 font-medium">{{ t('admin.borrowings.status') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="loan in items" :key="loan.id">
              <td class="px-4 py-3">
                <button type="button" class="text-left font-medium text-primary-700 hover:underline" @click="openDetail(loan)">{{ loan.lender?.name ?? '—' }}</button>
                <p class="text-xs text-neutral-500">{{ formatDate(loan.received_on) }}<template v-if="loan.reference"> · {{ loan.reference }}</template></p>
              </td>
              <td class="px-4 py-3 text-neutral-700">{{ loan.borrowed_for?.name ?? '—' }}</td>
              <td class="px-4 py-3 text-right tabular-nums">{{ money(loan.amount, loan.currency) }}</td>
              <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ money(loan.outstanding, loan.currency) }}</td>
              <td class="px-4 py-3 text-neutral-700">{{ interestLabel(loan) }}</td>
              <td v-if="loan.flexible" class="px-4 py-3 text-right text-neutral-500">{{ t('admin.borrowings.flexible') }}</td>
              <td v-else class="px-4 py-3 text-right tabular-nums">
                {{ money(loan.monthly_payment, loan.currency) }}
                <p class="text-xs text-neutral-500">× {{ loan.term_months }}</p>
              </td>
              <td class="px-4 py-3 text-neutral-700">
                <template v-if="loan.next_payment">
                  <span :class="loan.next_payment.late ? 'font-medium text-danger-700' : ''">{{ formatDate(loan.next_payment.due_on) }}</span>
                  <p class="text-xs text-neutral-500">{{ money((loan.next_payment.due_principal ?? loan.next_payment.principal) + loan.next_payment.interest, loan.currency) }}</p>
                </template>
                <template v-else>—</template>
              </td>
              <td class="px-4 py-3">
                <BaseBadge v-if="loan.late_count" variant="danger">{{ t('admin.borrowings.lateCount', { count: loan.late_count }) }}</BaseBadge>
                <BaseBadge v-else :variant="statusVariant[loan.status]">{{ t(`admin.borrowings.statuses.${loan.status}`) }}</BaseBadge>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <!-- New / edit -->
    <BaseModal v-model="formOpen" size="lg" :title="editing ? t('admin.borrowings.editLoan') : t('admin.borrowings.addLoan')">
      <form class="space-y-4" @submit.prevent="save">
        <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
        <BaseAlert v-if="locked" variant="info">{{ t('admin.borrowings.lockedHint') }}</BaseAlert>
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <SearchableSelect v-model="form.lender_id" required :options="lenderOptions" :label="t('admin.borrowings.lender')" :placeholder="t('admin.borrowings.selectLender')" :error="errors.lender_id?.[0]" />
            <button type="button" class="mt-1 text-xs font-medium text-primary-600 hover:text-primary-700" @click="lenderModalOpen = true">{{ t('admin.expenses.addExpenseFor') }}</button>
          </div>
          <div>
            <SearchableSelect
              v-model="form.expense_entity_id"
              required
              :options="entityOptions"
              :label="t('admin.borrowings.borrowedFor')"
              :placeholder="t('admin.borrowings.selectBorrowedFor')"
              :error="errors.expense_entity_id?.[0]"
            />
            <button v-if="auth.can('expense.create')" type="button" class="mt-1 text-xs font-medium text-primary-600 hover:text-primary-700" @click="entityModalOpen = true">
              {{ t('admin.expenses.addExpenseFor') }}
            </button>
          </div>
        </div>

        <fieldset :disabled="locked" class="space-y-4 disabled:opacity-60">
        <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_8rem_minmax(0,1fr)]">
          <BaseInput v-model="form.amount" type="number" required :label="t('admin.borrowings.amount')" :error="errors.amount?.[0]" />
          <BaseSelect :model-value="form.currency" :options="currencyOptions" :label="t('admin.borrowings.currency')" @update:model-value="form.currency = $event as BorrowingCurrency" />
          <BaseInput v-model="form.received_on" type="date" required :label="t('admin.borrowings.receivedOn')" :error="errors.received_on?.[0]" />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <SearchableSelect
            v-model="form.cash_account_id"
            :options="cashOptions"
            :label="t('admin.borrowings.receivedInto')"
            :placeholder="t('admin.borrowings.selectAccount')"
            :hint="t('admin.borrowings.receivedIntoHint')"
            :error="errors.cash_account_id?.[0]"
          />
          <BaseInput v-model="form.reference" :label="t('admin.borrowings.reference')" :error="errors.reference?.[0]" />
        </div>

        <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_8rem_10rem]">
          <BaseSelect :model-value="form.interest_type" :options="interestOptions" :label="t('admin.borrowings.interest')" :hint="interestHint" @update:model-value="form.interest_type = $event as InterestType" />
          <template v-if="form.interest_type !== 'none'">
            <BaseInput v-model="form.interest_rate" type="number" required :label="t('admin.borrowings.rate')" :error="errors.interest_rate?.[0]" />
            <BaseSelect :model-value="form.interest_period" :options="periodOptions" :label="t('admin.borrowings.ratePer')" @update:model-value="form.interest_period = $event as InterestPeriod" />
          </template>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput v-model="form.term_months" type="number" :label="t('admin.borrowings.termMonths')" :hint="t('admin.borrowings.termMonthsHint')" :error="errors.term_months?.[0]" />
          <BaseInput
            v-model="form.first_payment_on"
            type="date"
            :disabled="!(Number(form.term_months) > 0)"
            :label="t('admin.borrowings.firstPayment')"
            :hint="t('admin.borrowings.firstPaymentHint')"
            :error="errors.first_payment_on?.[0]"
          />
        </div>
        </fieldset>

        <BaseInput v-model="form.purpose" :label="t('admin.borrowings.purpose')" :error="errors.purpose?.[0]" />

        <div v-if="!locked && !(Number(form.term_months) > 0)" class="rounded-lg bg-primary-50 px-3 py-2 text-sm text-primary-900">{{ t('admin.borrowings.flexibleHint') }}</div>
        <div v-else-if="preview && !locked" class="rounded-lg bg-primary-50 px-3 py-2 text-sm text-primary-900">
          {{
            t('admin.borrowings.previewLine', {
              monthly: money(preview.schedule[0]?.total ?? 0, form.currency),
              months: preview.schedule.length,
              interest: money(preview.totals.interest, form.currency),
              total: money(preview.totals.total, form.currency),
              last: formatDate(preview.schedule[preview.schedule.length - 1]?.due_on),
            })
          }}
        </div>
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="formOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" :disabled="!form.lender_id || !form.expense_entity_id" @click="save">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>

    <!-- One loan -->
    <BaseModal :model-value="detail !== null" size="lg" :title="detail ? `${detail.lender?.name ?? '—'} — ${money(detail.amount, detail.currency)}` : ''" @update:model-value="detail = null">
      <template v-if="detail">
        <BaseAlert v-if="detailError" variant="danger" class="mb-3">{{ detailError }}</BaseAlert>
        <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
          <div><dt class="text-neutral-500">{{ t('admin.borrowings.borrowedFor') }}</dt><dd class="font-semibold">{{ detail.borrowed_for?.name ?? '—' }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.borrowings.receivedOn') }}</dt><dd class="font-semibold">{{ formatDate(detail.received_on) }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.borrowings.interest') }}</dt><dd class="font-semibold">{{ interestLabel(detail) }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.borrowings.status') }}</dt><dd><BaseBadge :variant="statusVariant[detail.status]">{{ t(`admin.borrowings.statuses.${detail.status}`) }}</BaseBadge></dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.borrowings.outstanding') }}</dt><dd class="font-semibold text-primary-800">{{ money(detail.outstanding, detail.currency) }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.borrowings.paidSoFar') }}</dt><dd class="font-semibold">{{ money(detail.paid_principal + detail.paid_interest, detail.currency) }}</dd></div>
          <template v-if="detail.flexible">
            <div><dt class="text-neutral-500">{{ t('admin.borrowings.plan') }}</dt><dd class="font-semibold">{{ t('admin.borrowings.flexible') }}</dd></div>
            <div><dt class="text-neutral-500">{{ t('admin.borrowings.interestPaidSoFar') }}</dt><dd class="font-semibold">{{ money(detail.paid_interest, detail.currency) }}</dd></div>
          </template>
          <template v-else>
            <div><dt class="text-neutral-500">{{ t('admin.borrowings.totalInterest') }}</dt><dd class="font-semibold">{{ money(detail.totals.interest, detail.currency) }}</dd></div>
            <div><dt class="text-neutral-500">{{ t('admin.borrowings.totalToPay') }}</dt><dd class="font-semibold">{{ money(detail.totals.total, detail.currency) }}</dd></div>
          </template>
          <div><dt class="text-neutral-500">{{ t('admin.borrowings.receivedInto') }}</dt><dd class="font-semibold">{{ detail.cash_account?.name ?? '—' }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.borrowings.reference') }}</dt><dd class="font-semibold">{{ detail.reference ?? '—' }}</dd></div>
        </dl>
        <p v-if="detail.purpose" class="mt-2 text-sm text-neutral-600">{{ detail.purpose }}</p>
        <p v-if="!detail.posted && detail.status === 'active'" class="mt-2 text-xs text-warning-700">{{ t('admin.borrowings.notPostedHint') }}</p>

        <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
          <span class="text-neutral-500">{{ t('admin.borrowings.contract') }}:</span>
          <button v-if="detail.has_contract" type="button" class="font-medium text-primary-700 hover:underline" @click="downloadContract(detail)">{{ detail.contract_name }}</button>
          <span v-else class="text-neutral-400">{{ t('admin.borrowings.noContract') }}</span>
          <label v-if="canManage" class="cursor-pointer rounded-lg bg-primary-50 px-2.5 py-1 text-xs font-medium text-primary-700 hover:bg-primary-100">
            {{ uploading ? t('admin.borrowings.uploading') : detail.has_contract ? t('admin.borrowings.replaceContract') : t('admin.borrowings.uploadContract') }}
            <input type="file" class="hidden" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" :disabled="uploading" @change="onContract" />
          </label>
        </div>

        <p v-if="detail.flexible" class="mt-4 rounded-lg bg-neutral-50 px-3 py-3 text-sm text-neutral-600">{{ t('admin.borrowings.flexibleHint') }}</p>
        <h3 v-else class="mb-2 mt-4 text-sm font-semibold text-neutral-800">{{ t('admin.borrowings.schedule') }}</h3>
        <div v-if="detailLoading" class="flex justify-center py-4"><BaseSpinner /></div>
        <div v-else-if="detail.schedule && !detail.flexible" class="max-h-80 overflow-auto rounded-lg border border-neutral-200">
          <table class="w-full text-left text-sm">
            <thead class="sticky top-0 bg-neutral-50 text-neutral-500">
              <tr>
                <th class="px-3 py-2 font-medium">#</th>
                <th class="px-3 py-2 font-medium">{{ t('admin.borrowings.dueOn') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('admin.borrowings.principal') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('admin.borrowings.interest') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('admin.borrowings.payment') }}</th>
                <th class="hidden px-3 py-2 text-right font-medium sm:table-cell">{{ t('admin.borrowings.balance') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="row in detail.schedule" :key="row.no" :class="[detail.next_payment?.no === row.no ? 'bg-primary-50' : '', row.paid ? 'text-neutral-400' : '']">
                <td class="px-3 py-2 text-neutral-500">{{ row.no }}</td>
                <td class="px-3 py-2">
                  {{ formatDate(row.due_on) }}
                  <BaseBadge v-if="row.paid" variant="success" class="ml-1">{{ t('admin.borrowings.paid') }}</BaseBadge>
                  <BaseBadge v-else-if="row.late && detail.status === 'active'" variant="danger" class="ml-1">{{ t('admin.borrowings.late') }}</BaseBadge>
                </td>
                <td class="px-3 py-2 text-right tabular-nums">{{ money(row.principal, detail.currency) }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ money(row.interest, detail.currency) }}</td>
                <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ money(row.total, detail.currency) }}</td>
                <td class="hidden px-3 py-2 text-right tabular-nums sm:table-cell">{{ money(row.balance, detail.currency) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <h3 class="mb-2 mt-4 text-sm font-semibold text-neutral-800">{{ t('admin.borrowings.history') }}</h3>
        <p v-if="!detail.repayments || detail.repayments.length === 0" class="rounded-lg bg-neutral-50 px-3 py-3 text-center text-sm text-neutral-500">{{ t('admin.borrowings.noRepayments') }}</p>
        <ul v-else class="divide-y divide-neutral-100 rounded-lg border border-neutral-200">
          <li v-for="row in detail.repayments" :key="row.id" class="flex items-center justify-between gap-2 px-3 py-2 text-sm">
            <div class="min-w-0">
              <p class="font-medium text-neutral-800">
                {{ money(row.amount, detail.currency) }}
                <span class="font-normal text-neutral-500">· {{ formatDate(row.paid_on) }}<template v-if="row.cash_account"> · {{ row.cash_account.name }}</template></span>
              </p>
              <p class="text-xs text-neutral-500">
                {{ t('admin.borrowings.principal') }} {{ money(row.principal, detail.currency) }} · {{ t('admin.borrowings.interest') }} {{ money(row.interest, detail.currency) }}
                <template v-if="row.note"> · {{ row.note }}</template>
              </p>
            </div>
            <button v-if="canManage && detail.status !== 'cancelled'" type="button" class="shrink-0 text-sm font-medium text-danger-600" @click="removeRepayment(row)">{{ t('admin.borrowings.delete') }}</button>
          </li>
        </ul>
      </template>
      <template #footer>
        <BaseButton v-if="detail" variant="outline" @click="router.push(`/admin/borrowings/loans/${detail.id}/statement`)">{{ t('admin.borrowings.statement') }}</BaseButton>
        <template v-if="detail && canManage">
          <BaseButton v-if="detail.status === 'active'" @click="payOpen = true">{{ t('admin.borrowings.recordPayment') }}</BaseButton>
          <BaseButton v-if="detail.status === 'active'" variant="outline" @click="openForm(detail)">{{ t('admin.borrowings.edit') }}</BaseButton>
          <BaseButton v-if="detail.status === 'active' && !hasPayments(detail)" variant="outline" @click="cancelLoan(detail)">{{ t('admin.borrowings.cancelLoan') }}</BaseButton>
          <BaseButton v-if="!hasPayments(detail)" variant="danger" @click="removeLoan(detail)">{{ t('admin.borrowings.delete') }}</BaseButton>
        </template>
        <BaseButton variant="outline" @click="detail = null">{{ t('common.close') }}</BaseButton>
      </template>
    </BaseModal>

    <BorrowingRepaymentModal v-model="payOpen" :loan="detail" @saved="onPaid" />
    <LenderFormModal v-model="lenderModalOpen" @saved="onLenderSaved" />
    <ExpenseEntityFormModal v-model="entityModalOpen" @saved="onEntitySaved" />
  </div>
</template>
