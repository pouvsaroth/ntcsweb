<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

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
import { payAmountLabel, staffLoansService, type LoanStatus, type LoanType, type StaffLoan } from '@/services/payroll'
import { staffService, type Staff } from '@/services/staff'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * HRM > Payroll > Loan/advance — money lent to staff, in their salary
 * currency, paid back by a fixed installment from each payroll from a date
 * on, or in cash here. Settles itself once paid back; can be cancelled (the
 * rest written off). Cards on a phone, a table from `sm` up.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => auth.can('payroll.manage'))

const statusFilter = ref<LoanStatus | ''>('active')
const { items, meta, loading, error, setPage, setFilter, fetch } = usePaginatedResource<StaffLoan>((query) => staffLoansService.list(query))
watch(statusFilter, (status) => setFilter('status', status || undefined))

const statusOptions = computed(() => [
  { value: '', label: t('admin.payroll.loans.all') },
  { value: 'active', label: t('admin.payroll.loans.status.active') },
  { value: 'settled', label: t('admin.payroll.loans.status.settled') },
  { value: 'cancelled', label: t('admin.payroll.loans.status.cancelled') },
])
const statusVariant: Record<LoanStatus, 'primary' | 'success' | 'neutral'> = { active: 'primary', settled: 'success', cancelled: 'neutral' }

const money = (loan: StaffLoan, value: number | string) => payAmountLabel(value, 'fixed', loan.currency)

function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

/** The 1st of next month — when an installment usually starts. */
function nextMonth(): string {
  const now = new Date()
  const next = new Date(now.getFullYear(), now.getMonth() + 1, 1)
  return `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}-01`
}

// --- New / edit loan ---------------------------------------------------------------------

const staff = ref<Staff[]>([])
const staffOptions = computed(() => staff.value.map((s) => ({ value: String(s.id), label: s.full_name, hint: s.employee_code })))
const typeOptions = computed(() => [
  { value: 'loan', label: t('admin.payroll.loans.type.loan') },
  { value: 'advance', label: t('admin.payroll.loans.type.advance') },
])

const formOpen = ref(false)
const editing = ref<StaffLoan | null>(null)
const form = reactive({ staff_id: '', type: 'advance' as LoanType, amount: '', issued_on: '', installment_amount: '', first_deduction_on: '', reason: '' })
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

async function openForm(loan: StaffLoan | null) {
  editing.value = loan
  form.staff_id = loan?.staff ? String(loan.staff.id) : ''
  form.type = loan?.type ?? 'advance'
  form.amount = loan ? String(Number(loan.amount)) : ''
  form.issued_on = loan?.issued_on ?? today()
  form.installment_amount = loan ? String(Number(loan.installment_amount)) : ''
  form.first_deduction_on = loan?.first_deduction_on ?? nextMonth()
  form.reason = loan?.reason ?? ''
  errors.value = {}
  saveError.value = null
  formOpen.value = true
  if (staff.value.length === 0) staff.value = await staffService.listAll().catch(() => [])
}

// An advance is usually taken back whole from the next payroll.
watch(() => [form.type, form.amount], () => {
  if (!editing.value && form.type === 'advance') form.installment_amount = form.amount
})

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  const input = {
    amount: Number(form.amount || 0),
    installment_amount: Number(form.installment_amount || 0),
    first_deduction_on: form.first_deduction_on,
    reason: form.reason.trim() || null,
  }
  try {
    if (editing.value) {
      const updated = await staffLoansService.update(editing.value.id, input)
      if (detail.value?.id === updated.id) await openDetail(updated)
    } else {
      await staffLoansService.create({ ...input, staff_id: Number(form.staff_id), type: form.type, issued_on: form.issued_on })
    }
    formOpen.value = false
    await fetch()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.payroll.saveFailed')
  } finally {
    saving.value = false
  }
}

// --- One loan: repayments ------------------------------------------------------------------

const detail = ref<StaffLoan | null>(null)
const detailLoading = ref(false)
const detailError = ref<string | null>(null)
const repay = reactive({ amount: '', paid_on: '', note: '' })
const repayErrors = ref<Record<string, string[]>>({})
const repaying = ref(false)

async function openDetail(loan: StaffLoan) {
  detail.value = loan
  detailError.value = null
  repay.amount = ''
  repay.paid_on = today()
  repay.note = ''
  repayErrors.value = {}
  detailLoading.value = true
  try {
    detail.value = await staffLoansService.get(loan.id)
  } finally {
    detailLoading.value = false
  }
}

async function recordRepayment() {
  if (!detail.value) return
  repaying.value = true
  repayErrors.value = {}
  detailError.value = null
  try {
    detail.value = await staffLoansService.repay(detail.value.id, { amount: Number(repay.amount || 0), paid_on: repay.paid_on, note: repay.note.trim() || null })
    repay.amount = ''
    repay.note = ''
    await fetch()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) repayErrors.value = e.errors
    else detailError.value = e instanceof ApiRequestError ? e.message : t('admin.payroll.saveFailed')
  } finally {
    repaying.value = false
  }
}

async function removeRepayment(id: number) {
  if (!(await confirmDialog.confirm({ message: t('admin.payroll.loans.removeRepaymentConfirm'), danger: true }))) return
  detailError.value = null
  try {
    detail.value = await staffLoansService.removeRepayment(id)
    await fetch()
  } catch (e) {
    detailError.value = e instanceof ApiRequestError ? e.message : t('admin.organization.deleteFailed')
  }
}

async function cancel(loan: StaffLoan) {
  if (!(await confirmDialog.confirm({ message: t('admin.payroll.loans.cancelConfirm', { amount: money(loan, loan.balance) }), danger: true }))) return
  detailError.value = null
  try {
    detail.value = await staffLoansService.cancel(loan.id)
    await fetch()
  } catch (e) {
    detailError.value = e instanceof ApiRequestError ? e.message : t('admin.payroll.saveFailed')
  }
}

async function remove(loan: StaffLoan) {
  if (!(await confirmDialog.confirm({ message: t('admin.payroll.loans.deleteConfirm'), danger: true }))) return
  detailError.value = null
  try {
    await staffLoansService.remove(loan.id)
    detail.value = null
    await fetch()
  } catch (e) {
    detailError.value = e instanceof ApiRequestError ? e.message : t('admin.organization.deleteFailed')
  }
}

onMounted(() => {
  setFilter('status', statusFilter.value || undefined)
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <BaseSelect class="w-full sm:w-48" :model-value="statusFilter" :options="statusOptions" @update:model-value="statusFilter = $event as LoanStatus | ''" />
      <BaseButton v-if="canManage" @click="openForm(null)">{{ t('admin.payroll.loans.add') }}</BaseButton>
    </div>
    <p class="mb-4 text-sm text-neutral-500">{{ t('admin.payroll.loans.hint') }}</p>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
    <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('admin.payroll.loans.empty') }}</p>
    <template v-else>
      <div class="space-y-2 sm:hidden">
        <button
          v-for="loan in items"
          :key="loan.id"
          type="button"
          class="block w-full rounded-[--radius-card] border border-neutral-200 bg-white p-3 text-left shadow-[--shadow-card]"
          @click="openDetail(loan)"
        >
          <div class="flex items-start justify-between gap-2">
            <p class="text-sm font-semibold text-neutral-800">{{ loan.staff?.name ?? '—' }} <span class="font-normal text-neutral-500">({{ loan.staff?.employee_code }})</span></p>
            <BaseBadge :variant="statusVariant[loan.status]">{{ t(`admin.payroll.loans.status.${loan.status}`) }}</BaseBadge>
          </div>
          <p class="text-sm text-neutral-700">{{ t(`admin.payroll.loans.type.${loan.type}`) }} · {{ money(loan, loan.amount) }}</p>
          <p class="text-xs text-neutral-500">{{ t('admin.payroll.loans.balanceOf', { amount: money(loan, loan.balance) }) }} · {{ t('admin.payroll.loans.installmentOf', { amount: money(loan, loan.installment_amount) }) }}</p>
        </button>
      </div>
      <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block">
        <table class="w-full text-left text-sm">
          <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-500">
            <tr>
              <th class="px-4 py-3 font-medium">{{ t('admin.payroll.staff') }}</th>
              <th class="px-4 py-3 font-medium">{{ t('admin.payroll.loans.kind') }}</th>
              <th class="px-4 py-3 text-right font-medium">{{ t('admin.payroll.amount') }}</th>
              <th class="px-4 py-3 text-right font-medium">{{ t('admin.payroll.loans.installment') }}</th>
              <th class="px-4 py-3 text-right font-medium">{{ t('admin.payroll.loans.balance') }}</th>
              <th class="px-4 py-3 font-medium">{{ t('admin.payroll.loans.from') }}</th>
              <th class="px-4 py-3 font-medium">{{ t('admin.organization.status') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="loan in items" :key="loan.id">
              <td class="px-4 py-3">
                <button type="button" class="text-left font-medium text-primary-700 hover:underline" @click="openDetail(loan)">{{ loan.staff?.name ?? '—' }}</button>
                <p class="text-xs text-neutral-500">{{ loan.staff?.employee_code }}</p>
              </td>
              <td class="px-4 py-3 text-neutral-700">
                {{ t(`admin.payroll.loans.type.${loan.type}`) }}
                <p class="text-xs text-neutral-500">{{ formatDate(loan.issued_on) }}</p>
              </td>
              <td class="px-4 py-3 text-right tabular-nums">{{ money(loan, loan.amount) }}</td>
              <td class="px-4 py-3 text-right tabular-nums">{{ money(loan, loan.installment_amount) }}</td>
              <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ money(loan, loan.balance) }}</td>
              <td class="px-4 py-3 text-neutral-700">{{ formatDate(loan.first_deduction_on) }}</td>
              <td class="px-4 py-3"><BaseBadge :variant="statusVariant[loan.status]">{{ t(`admin.payroll.loans.status.${loan.status}`) }}</BaseBadge></td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <!-- New / edit -->
    <BaseModal v-model="formOpen" :title="editing ? t('admin.payroll.loans.edit') : t('admin.payroll.loans.add')">
      <form class="space-y-4" @submit.prevent="save">
        <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
        <template v-if="!editing">
          <SearchableSelect v-model="form.staff_id" required :options="staffOptions" :label="t('admin.payroll.staff')" :hint="t('admin.payroll.loans.currencyHint')" :error="errors.staff_id?.[0]" />
          <div class="grid gap-4 sm:grid-cols-2">
            <BaseSelect :model-value="form.type" :options="typeOptions" :label="t('admin.payroll.loans.kind')" @update:model-value="form.type = $event as LoanType" />
            <BaseInput v-model="form.issued_on" type="date" required :label="t('admin.payroll.loans.issuedOn')" :error="errors.issued_on?.[0]" />
          </div>
        </template>
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput v-model="form.amount" type="number" required :label="t('admin.payroll.amount')" :error="errors.amount?.[0]" />
          <BaseInput v-model="form.installment_amount" type="number" required :label="t('admin.payroll.loans.installmentLabel')" :error="errors.installment_amount?.[0]" />
        </div>
        <BaseInput v-model="form.first_deduction_on" type="date" required :label="t('admin.payroll.loans.firstDeduction')" :hint="t('admin.payroll.loans.firstDeductionHint')" :error="errors.first_deduction_on?.[0]" />
        <BaseInput v-model="form.reason" :label="t('admin.payroll.loans.reason')" :error="errors.reason?.[0]" />
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="formOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>

    <!-- One loan -->
    <BaseModal :model-value="detail !== null" size="lg" :title="detail ? `${detail.staff?.name ?? '—'} — ${t(`admin.payroll.loans.type.${detail.type}`)}` : ''" @update:model-value="detail = null">
      <template v-if="detail">
        <BaseAlert v-if="detailError" variant="danger" class="mb-3">{{ detailError }}</BaseAlert>
        <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
          <div><dt class="text-neutral-500">{{ t('admin.payroll.amount') }}</dt><dd class="font-semibold">{{ money(detail, detail.amount) }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.payroll.loans.repaid') }}</dt><dd class="font-semibold">{{ money(detail, detail.repaid) }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.payroll.loans.balance') }}</dt><dd class="font-semibold">{{ money(detail, detail.balance) }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.organization.status') }}</dt><dd><BaseBadge :variant="statusVariant[detail.status]">{{ t(`admin.payroll.loans.status.${detail.status}`) }}</BaseBadge></dd></div>
        </dl>
        <p class="mt-2 text-sm text-neutral-600">
          {{ t('admin.payroll.loans.plan', { amount: money(detail, detail.installment_amount), date: formatDate(detail.first_deduction_on) }) }}
          <template v-if="detail.reason"> · {{ detail.reason }}</template>
        </p>

        <h3 class="mb-2 mt-4 text-sm font-semibold text-neutral-800">{{ t('admin.payroll.loans.repayments') }}</h3>
        <div v-if="detailLoading" class="flex justify-center py-4"><BaseSpinner /></div>
        <p v-else-if="!detail.repayments || detail.repayments.length === 0" class="rounded-lg bg-neutral-50 px-3 py-4 text-center text-sm text-neutral-500">{{ t('admin.payroll.loans.noRepayments') }}</p>
        <ul v-else class="divide-y divide-neutral-100 rounded-lg border border-neutral-200">
          <li v-for="repayment in detail.repayments" :key="repayment.id" class="flex items-center justify-between gap-2 px-3 py-2 text-sm">
            <div>
              <p class="font-medium text-neutral-800">{{ money(detail, repayment.amount) }} <span class="font-normal text-neutral-500">· {{ formatDate(repayment.paid_on) }} · {{ t(`admin.payroll.loans.method.${repayment.method}`) }}</span></p>
              <p v-if="repayment.note" class="text-xs text-neutral-600">{{ repayment.note }}</p>
            </div>
            <button v-if="canManage && repayment.method === 'cash' && detail.status !== 'cancelled'" type="button" class="text-sm font-medium text-danger-600" @click="removeRepayment(repayment.id)">{{ t('admin.organization.delete') }}</button>
          </li>
        </ul>

        <form v-if="canManage && detail.status === 'active'" class="mt-4 grid items-end gap-2 sm:grid-cols-[8rem_10rem_minmax(0,1fr)_auto]" @submit.prevent="recordRepayment">
          <BaseInput v-model="repay.amount" type="number" :label="t('admin.payroll.loans.cashRepayment')" :error="repayErrors.amount?.[0]" />
          <BaseInput v-model="repay.paid_on" type="date" :label="t('admin.payroll.loans.paidOn')" :error="repayErrors.paid_on?.[0]" />
          <BaseInput v-model="repay.note" :label="t('admin.payroll.note')" />
          <BaseButton :loading="repaying" @click="recordRepayment">{{ t('admin.payroll.loans.record') }}</BaseButton>
        </form>
      </template>
      <template #footer>
        <template v-if="detail && canManage">
          <BaseButton v-if="detail.status !== 'cancelled'" variant="outline" @click="openForm(detail)">{{ t('admin.payroll.edit') }}</BaseButton>
          <BaseButton v-if="detail.status === 'active'" variant="outline" @click="cancel(detail)">{{ t('admin.payroll.loans.cancel') }}</BaseButton>
          <BaseButton v-if="!detail.repayments || detail.repayments.length === 0" variant="danger" @click="remove(detail)">{{ t('admin.organization.delete') }}</BaseButton>
        </template>
        <BaseButton variant="outline" @click="detail = null">{{ t('common.close') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
