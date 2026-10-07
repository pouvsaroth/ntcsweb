<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import PayslipModal from '@/pages/admin/payroll/PayslipModal.vue'
import { runStatusVariant } from '@/pages/admin/payroll/runStatus'
import { payAmountLabel, payrollRunsService, runPeriodLabel, type PayAccounts, type PayrollRun, type Payslip } from '@/services/payroll'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { useSiteStore } from '@/stores/site'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'
import { printPayslips } from '@/utils/printPayslip'

/**
 * One payroll run (HRM > Payroll) — its totals per currency and every
 * payslip, with what can be done next for its status: recalculate, adjust
 * a payslip and send for approval (draft / rejected); approve or reject
 * (pending, for whoever can decide); pay into Accounting (approved);
 * cancel; print payslips. Cards on a phone, a table from `sm` up.
 */
const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()
const auth = useAuthStore()
const site = useSiteStore()
const confirmDialog = useConfirmDialogStore()

const canRun = computed(() => auth.can('payroll.run'))

const run = ref<PayrollRun | null>(null)
const loading = ref(false)
const busy = ref(false)
const error = ref<string | null>(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    run.value = await payrollRunsService.get(Number(route.params.id))
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.payroll.loadFailed')
  } finally {
    loading.value = false
  }
}

const editable = computed(() => run.value !== null && ['draft', 'rejected'].includes(run.value.status))
const payslips = computed<Payslip[]>(() => run.value?.payslips ?? [])
const money = (slip: { currency: 'USD' | 'KHR' }, amount: number) => payAmountLabel(amount, 'fixed', slip.currency)
const deductionsOf = (slip: Payslip) => slip.attendance_deduction + slip.other_deductions + slip.loan_deduction + Math.max(-slip.adjustment, 0)

async function act(action: () => Promise<PayrollRun>, confirm?: string) {
  if (confirm && !(await confirmDialog.confirm({ message: confirm }))) return
  busy.value = true
  error.value = null
  try {
    run.value = await action()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.payroll.saveFailed')
  } finally {
    busy.value = false
  }
}

const recalculate = () => act(() => payrollRunsService.recalculate(run.value!.id))
const submit = () => act(() => payrollRunsService.submit(run.value!.id), t('admin.payroll.runs.submitConfirm'))
const approve = () => act(() => payrollRunsService.approve(run.value!.id), t('admin.payroll.runs.approveConfirm', { reference: run.value!.reference }))

async function cancel() {
  if (!(await confirmDialog.confirm({ message: t('admin.payroll.runs.cancelConfirm', { reference: run.value!.reference }), danger: true }))) return
  await act(() => payrollRunsService.cancel(run.value!.id))
}

// --- Reject ---------------------------------------------------------------------------------

const rejectOpen = ref(false)
const rejectReason = ref('')
const rejectError = ref<string | null>(null)

async function reject() {
  rejectError.value = null
  try {
    run.value = await payrollRunsService.reject(run.value!.id, rejectReason.value.trim())
    rejectOpen.value = false
    rejectReason.value = ''
  } catch (e) {
    rejectError.value = e instanceof ApiRequestError ? (e.errors?.reason?.[0] ?? e.message) : t('admin.payroll.saveFailed')
  }
}

// --- Adjust a payslip ----------------------------------------------------------------------

const adjusting = ref<Payslip | null>(null)
const adjust = reactive({ amount: '', note: '' })
const adjustError = ref<string | null>(null)

function openAdjust(slip: Payslip) {
  adjusting.value = slip
  adjust.amount = slip.adjustment ? String(slip.adjustment) : ''
  adjust.note = slip.adjustment_note ?? ''
  adjustError.value = null
}

async function saveAdjust() {
  if (!adjusting.value) return
  adjustError.value = null
  try {
    run.value = await payrollRunsService.adjust(adjusting.value.id, { adjustment: Number(adjust.amount || 0), adjustment_note: adjust.note.trim() || null })
    adjusting.value = null
  } catch (e) {
    adjustError.value = e instanceof ApiRequestError ? e.message : t('admin.payroll.saveFailed')
  }
}

// --- Pay ------------------------------------------------------------------------------------

const payOpen = ref(false)
const accounts = ref<PayAccounts | null>(null)
const pay = reactive({ expense_account_id: '', cash_account_id: '', paid_on: '' })
const payErrors = ref<Record<string, string[]>>({})
const payError = ref<string | null>(null)

async function openPay() {
  payErrors.value = {}
  payError.value = null
  pay.paid_on = run.value?.pay_date ?? ''
  payOpen.value = true
  if (!accounts.value) {
    accounts.value = await payrollRunsService.payAccounts().catch(() => ({ expense: [], cash: [] }))
    pay.expense_account_id = accounts.value.expense[0] ? String(accounts.value.expense[0].id) : ''
    pay.cash_account_id = accounts.value.cash[0] ? String(accounts.value.cash[0].id) : ''
  }
}

async function confirmPay() {
  payErrors.value = {}
  payError.value = null
  busy.value = true
  try {
    run.value = await payrollRunsService.pay(run.value!.id, {
      expense_account_id: Number(pay.expense_account_id),
      cash_account_id: Number(pay.cash_account_id),
      paid_on: pay.paid_on,
    })
    payOpen.value = false
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) payErrors.value = e.errors
    payError.value = e instanceof ApiRequestError ? e.message : t('admin.payroll.saveFailed')
  } finally {
    busy.value = false
  }
}

const accountOption = (a: { id: number; code: string; name: string }) => ({ value: String(a.id), label: `${a.code} — ${a.name}` })

// --- Payslips ---------------------------------------------------------------------------------

const viewing = ref<number | null>(null)

async function printAll() {
  if (!run.value) return
  busy.value = true
  try {
    const full = await Promise.all(payslips.value.map((slip) => payrollRunsService.payslip(slip.id)))
    printPayslips(full, site.info)
  } finally {
    busy.value = false
  }
}

onMounted(() => load())
</script>

<template>
  <div>
    <button type="button" class="mb-4 flex items-center gap-1.5 text-sm font-medium text-primary-700 hover:underline" @click="router.back()">
      <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
      {{ t('admin.payroll.runs.back') }}
    </button>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <div v-if="loading && !run" class="flex justify-center py-10"><BaseSpinner /></div>

    <template v-if="run">
      <!-- Header -->
      <section class="mb-4 rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="flex items-center gap-2 text-lg font-semibold text-neutral-900">
              {{ run.reference }}
              <BaseBadge :variant="runStatusVariant[run.status]">{{ t(`admin.payroll.runs.status.${run.status}`) }}</BaseBadge>
            </h2>
            <p class="text-sm text-neutral-600">
              {{ runPeriodLabel(run, locale) }} · {{ formatDate(run.period_start) }} – {{ formatDate(run.period_end) }} · {{ t('admin.payroll.payslip.payDate') }} {{ formatDate(run.pay_date) }}
            </p>
            <p v-if="run.approval_flow && run.status === 'pending'" class="text-xs text-neutral-500">
              {{ t('admin.approvals.flowStep', { step: run.approval_flow.step, total: run.approval_flow.total, group: run.approval_flow.group ?? '—' }) }}
            </p>
            <p v-if="run.status === 'rejected' && run.decision_reason" class="mt-1 rounded-lg bg-red-50 px-3 py-1.5 text-sm text-red-700">
              {{ t('admin.leaveRequests.decisionReason') }}: {{ run.decision_reason }}
            </p>
            <p v-if="run.note" class="mt-1 text-sm text-neutral-600">{{ run.note }}</p>
          </div>
          <div class="flex flex-wrap gap-2">
            <template v-if="canRun && editable">
              <BaseButton variant="outline" :loading="busy" @click="recalculate">{{ t('admin.payroll.runs.recalculate') }}</BaseButton>
              <BaseButton :loading="busy" :disabled="payslips.length === 0" @click="submit">{{ t('admin.payroll.runs.submit') }}</BaseButton>
            </template>
            <template v-if="run.can_decide">
              <BaseButton variant="danger" :disabled="busy" @click="rejectOpen = true">{{ t('admin.payroll.runs.reject') }}</BaseButton>
              <BaseButton :loading="busy" @click="approve">{{ t('admin.payroll.runs.approve') }}</BaseButton>
            </template>
            <BaseButton v-if="canRun && run.status === 'approved'" :loading="busy" @click="openPay">{{ t('admin.payroll.runs.pay') }}</BaseButton>
            <BaseButton v-if="payslips.length" variant="outline" :loading="busy" @click="printAll">{{ t('admin.payroll.runs.printAll') }}</BaseButton>
            <BaseButton v-if="canRun && !['paid', 'cancelled'].includes(run.status)" variant="ghost" :disabled="busy" @click="cancel">{{ t('admin.payroll.runs.cancel') }}</BaseButton>
          </div>
        </div>

        <!-- Totals per currency -->
        <div class="mt-4 grid gap-3 sm:grid-cols-2">
          <div v-for="total in run.totals" :key="total.currency" class="rounded-lg bg-neutral-50 p-3">
            <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-neutral-500">{{ total.currency }} · {{ t('admin.payroll.runs.staffN', { count: total.staff_count }) }}</p>
            <dl class="grid grid-cols-2 gap-x-4 gap-y-0.5 text-sm sm:grid-cols-3">
              <div><dt class="text-neutral-500">{{ t('admin.payroll.payslip.gross') }}</dt><dd class="font-medium tabular-nums">{{ money(total, total.gross_pay) }}</dd></div>
              <div><dt class="text-neutral-500">{{ t('admin.payroll.tabs.tax') }}</dt><dd class="font-medium tabular-nums">{{ money(total, total.tax) }}</dd></div>
              <div><dt class="text-neutral-500">{{ t('admin.payroll.runs.nssfBoth') }}</dt><dd class="font-medium tabular-nums">{{ money(total, total.social_security_employee) }} + {{ money(total, total.social_security_employer) }}</dd></div>
              <div><dt class="text-neutral-500">{{ t('admin.payroll.tabs.deductions') }}</dt><dd class="font-medium tabular-nums">{{ money(total, total.attendance_deduction + total.other_deductions + total.loan_deduction) }}</dd></div>
              <div><dt class="text-neutral-500">{{ t('admin.payroll.payslip.net') }}</dt><dd class="font-semibold tabular-nums text-neutral-900">{{ money(total, total.net_pay) }}</dd></div>
            </dl>
          </div>
        </div>
        <p v-if="run.status === 'paid'" class="mt-3 text-sm text-green-700">
          {{ t('admin.payroll.runs.paidOn', { date: formatDate(run.paid_at ?? run.pay_date) }) }}<template v-if="run.expense"> · {{ t('admin.payroll.runs.expense', { number: run.expense.expense_number }) }}</template>
        </p>
      </section>

      <!-- Payslips -->
      <p v-if="payslips.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('admin.payroll.runs.noPayslips') }}</p>
      <template v-else>
        <div class="space-y-2 sm:hidden">
          <div v-for="slip in payslips" :key="slip.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
            <div class="flex items-start justify-between gap-2">
              <button type="button" class="text-left text-sm font-semibold text-primary-700" @click="viewing = slip.id">{{ slip.staff?.name }} <span class="font-normal text-neutral-500">({{ slip.staff?.employee_code }})</span></button>
              <span class="shrink-0 text-sm font-bold text-neutral-900">{{ money(slip, slip.net_pay) }}</span>
            </div>
            <p class="text-xs text-neutral-600">
              {{ t('admin.payroll.payslip.gross') }} {{ money(slip, slip.gross_pay) }} · {{ t('admin.payroll.tabs.tax') }} {{ money(slip, slip.tax) }} · NSSF {{ money(slip, slip.social_security_employee) }}
            </p>
            <p v-if="slip.adjustment" class="text-xs text-primary-700">{{ t('admin.payroll.lines.adjustment') }} {{ money(slip, slip.adjustment) }}<template v-if="slip.adjustment_note"> · {{ slip.adjustment_note }}</template></p>
            <div v-if="canRun && editable" class="mt-2 flex justify-end">
              <button type="button" class="text-sm font-medium text-primary-700" @click="openAdjust(slip)">{{ t('admin.payroll.runs.adjust') }}</button>
            </div>
          </div>
        </div>
        <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block">
          <table class="w-full text-left text-sm">
            <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-500">
              <tr>
                <th class="px-3 py-3 font-medium">{{ t('admin.payroll.staff') }}</th>
                <th class="px-3 py-3 text-right font-medium">{{ t('admin.payroll.tabs.basicSalary') }}</th>
                <th class="px-3 py-3 text-right font-medium">{{ t('admin.payroll.runs.extras') }}</th>
                <th class="px-3 py-3 text-right font-medium">{{ t('admin.payroll.payslip.gross') }}</th>
                <th class="px-3 py-3 text-right font-medium">{{ t('admin.payroll.tabs.deductions') }}</th>
                <th class="px-3 py-3 text-right font-medium">NSSF</th>
                <th class="px-3 py-3 text-right font-medium">{{ t('admin.payroll.tabs.tax') }}</th>
                <th class="px-3 py-3 text-right font-medium">{{ t('admin.payroll.payslip.net') }}</th>
                <th v-if="canRun && editable" class="px-3 py-3" />
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="slip in payslips" :key="slip.id">
                <td class="px-3 py-2.5">
                  <button type="button" class="text-left font-medium text-primary-700 hover:underline" @click="viewing = slip.id">{{ slip.staff?.name }}</button>
                  <p class="text-xs text-neutral-500">{{ slip.staff?.employee_code }} · {{ slip.currency }}</p>
                </td>
                <td class="px-3 py-2.5 text-right tabular-nums">{{ money(slip, slip.basic_pay) }}</td>
                <td class="px-3 py-2.5 text-right tabular-nums">{{ money(slip, slip.allowances + slip.bonuses + slip.overtime_pay) }}</td>
                <td class="px-3 py-2.5 text-right tabular-nums">{{ money(slip, slip.gross_pay) }}</td>
                <td class="px-3 py-2.5 text-right tabular-nums">
                  {{ money(slip, deductionsOf(slip)) }}
                  <p v-if="slip.adjustment > 0" class="text-xs text-primary-700">+ {{ money(slip, slip.adjustment) }}</p>
                </td>
                <td class="px-3 py-2.5 text-right tabular-nums">{{ money(slip, slip.social_security_employee) }}</td>
                <td class="px-3 py-2.5 text-right tabular-nums">{{ money(slip, slip.tax) }}</td>
                <td class="px-3 py-2.5 text-right font-semibold tabular-nums text-neutral-900">{{ money(slip, slip.net_pay) }}</td>
                <td v-if="canRun && editable" class="px-3 py-2.5 text-right">
                  <button type="button" class="text-sm font-medium text-primary-700 hover:underline" @click="openAdjust(slip)">{{ t('admin.payroll.runs.adjust') }}</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </template>

    <PayslipModal :payslip-id="viewing" @close="viewing = null" />

    <BaseModal :model-value="adjusting !== null" :title="adjusting ? `${t('admin.payroll.runs.adjust')} — ${adjusting.staff?.name ?? ''}` : ''" @update:model-value="adjusting = null">
      <form class="space-y-4" @submit.prevent="saveAdjust">
        <BaseAlert v-if="adjustError" variant="danger">{{ adjustError }}</BaseAlert>
        <p class="text-sm text-neutral-600">{{ t('admin.payroll.runs.adjustHint') }}</p>
        <BaseInput v-model="adjust.amount" type="number" :label="t('admin.payroll.runs.adjustAmount', { currency: adjusting?.currency ?? '' })" />
        <BaseInput v-model="adjust.note" :label="t('admin.payroll.note')" />
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="adjusting = null">{{ t('common.close') }}</BaseButton>
        <BaseButton @click="saveAdjust">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>

    <BaseModal v-model="rejectOpen" :title="t('admin.payroll.runs.reject')">
      <BaseAlert v-if="rejectError" variant="danger" class="mb-3">{{ rejectError }}</BaseAlert>
      <BaseInput v-model="rejectReason" required :label="t('admin.payroll.runs.rejectReason')" />
      <template #footer>
        <BaseButton variant="outline" @click="rejectOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton variant="danger" :disabled="!rejectReason.trim()" @click="reject">{{ t('admin.payroll.runs.reject') }}</BaseButton>
      </template>
    </BaseModal>

    <BaseModal v-model="payOpen" :title="t('admin.payroll.runs.pay')">
      <form class="space-y-4" @submit.prevent="confirmPay">
        <BaseAlert v-if="payError" variant="danger">{{ payError }}</BaseAlert>
        <p class="text-sm text-neutral-600">{{ t('admin.payroll.runs.payHint') }}</p>
        <BaseSelect v-model="pay.expense_account_id" required :options="(accounts?.expense ?? []).map(accountOption)" :label="t('admin.payroll.runs.expenseAccount')" :error="payErrors.expense_account_id?.[0]" />
        <BaseSelect v-model="pay.cash_account_id" required :options="(accounts?.cash ?? []).map(accountOption)" :label="t('admin.payroll.runs.cashAccount')" :error="payErrors.cash_account_id?.[0]" />
        <BaseInput v-model="pay.paid_on" type="date" required :label="t('admin.payroll.runs.paidOnLabel')" :error="payErrors.paid_on?.[0]" />
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="payOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="busy" :disabled="!pay.expense_account_id || !pay.cash_account_id" @click="confirmPay">{{ t('admin.payroll.runs.pay') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
