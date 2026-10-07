<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { hoursLabel, payAmountLabel, payrollRunsService, riel, type Payslip } from '@/services/payroll'
import { useSiteStore } from '@/stores/site'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'
import { payslipLineLabel } from '@/utils/payslipLines'
import { printPayslips } from '@/utils/printPayslip'

/** One payslip — the lines, the working behind them, and Print. Loads the full payslip by id. */
const props = defineProps<{ payslipId: number | null }>()
const emit = defineEmits<{ close: [] }>()

const { t } = useI18n()
const site = useSiteStore()

const slip = ref<Payslip | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

watch(
  () => props.payslipId,
  async (id) => {
    slip.value = null
    error.value = null
    if (id === null) return
    loading.value = true
    try {
      slip.value = await payrollRunsService.payslip(id)
    } catch (e) {
      error.value = e instanceof ApiRequestError ? e.message : t('admin.payroll.loadFailed')
    } finally {
      loading.value = false
    }
  },
  { immediate: true },
)

const money = (amount: number) => (slip.value ? payAmountLabel(amount, 'fixed', slip.value.currency) : '')
const totalDeductions = computed(() => slip.value?.lines.deductions.reduce((sum, l) => sum + l.amount, 0) ?? 0)
const totalEarnings = computed(() => slip.value?.lines.earnings.reduce((sum, l) => sum + l.amount, 0) ?? 0)
const attendance = computed(() => slip.value?.details?.attendance ?? null)
const overtime = computed(() => slip.value?.details?.overtime?.minutes ?? null)
</script>

<template>
  <BaseModal :model-value="payslipId !== null" size="lg" :title="slip ? `${slip.staff?.name ?? ''} — ${slip.run?.reference ?? ''}` : t('admin.payroll.payslip.title')" @update:model-value="emit('close')">
    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
    <BaseAlert v-else-if="error" variant="danger">{{ error }}</BaseAlert>
    <template v-else-if="slip">
      <dl class="mb-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
        <div><dt class="text-neutral-500">{{ t('admin.payroll.payslip.period') }}</dt><dd class="font-medium">{{ slip.run ? `${formatDate(slip.run.period_start)} – ${formatDate(slip.run.period_end)}` : '—' }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.payroll.payslip.payDate') }}</dt><dd class="font-medium">{{ slip.run ? formatDate(slip.run.pay_date) : '—' }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.payroll.salaries.monthlyBasic') }}</dt><dd class="font-medium">{{ money(slip.monthly_basic) }}</dd></div>
        <div>
          <dt class="text-neutral-500">{{ t('admin.payroll.salaries.payment') }}</dt>
          <dd class="font-medium">{{ slip.payment_method === 'bank' ? [slip.bank_name, slip.bank_account_number].filter(Boolean).join(' · ') || t('admin.payroll.salaries.bank') : t('admin.payroll.salaries.cash') }}</dd>
        </div>
      </dl>

      <div class="grid gap-4 sm:grid-cols-2">
        <section>
          <h3 class="mb-1 text-sm font-semibold text-neutral-800">{{ t('admin.payroll.payslip.earnings') }}</h3>
          <table class="w-full text-sm">
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(line, index) in slip.lines.earnings" :key="index">
                <td class="py-1.5 text-neutral-700">{{ payslipLineLabel(line, t) }}</td>
                <td class="py-1.5 text-right tabular-nums">{{ money(line.amount) }}</td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="border-t-2 border-neutral-300 font-semibold"><td class="py-1.5">{{ t('admin.payroll.payslip.gross') }}</td><td class="py-1.5 text-right tabular-nums">{{ money(totalEarnings) }}</td></tr>
            </tfoot>
          </table>
        </section>
        <section>
          <h3 class="mb-1 text-sm font-semibold text-neutral-800">{{ t('admin.payroll.payslip.deductions') }}</h3>
          <table class="w-full text-sm">
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(line, index) in slip.lines.deductions" :key="index">
                <td class="py-1.5 text-neutral-700">{{ payslipLineLabel(line, t) }}</td>
                <td class="py-1.5 text-right tabular-nums">{{ money(line.amount) }}</td>
              </tr>
              <tr v-if="slip.lines.deductions.length === 0"><td class="py-1.5 text-neutral-400" colspan="2">—</td></tr>
            </tbody>
            <tfoot>
              <tr class="border-t-2 border-neutral-300 font-semibold"><td class="py-1.5">{{ t('admin.payroll.payslip.totalDeductions') }}</td><td class="py-1.5 text-right tabular-nums">{{ money(totalDeductions) }}</td></tr>
            </tfoot>
          </table>
        </section>
      </div>

      <p class="mt-4 rounded-lg bg-primary-50 px-4 py-3 text-right text-base">
        {{ t('admin.payroll.payslip.net') }}: <span class="font-bold text-neutral-900">{{ money(slip.net_pay) }}</span>
      </p>
      <p v-if="slip.lines.employer.length" class="mt-1 text-right text-xs text-neutral-500">
        {{ t('admin.payroll.payslip.employerPaid') }}: <template v-for="(line, index) in slip.lines.employer" :key="index">{{ payslipLineLabel(line, t) }} {{ money(line.amount) }}</template>
      </p>

      <!-- The working -->
      <details class="mt-4 text-sm">
        <summary class="cursor-pointer font-medium text-primary-700">{{ t('admin.payroll.payslip.working') }}</summary>
        <dl class="mt-2 grid grid-cols-[minmax(0,1fr)_auto] gap-x-4 gap-y-1 rounded-lg bg-neutral-50 p-3">
          <template v-if="slip.details?.structure"><dt class="text-neutral-500">{{ t('admin.payroll.salaries.structure') }}</dt><dd>{{ slip.details.structure }}</dd></template>
          <template v-if="overtime"><dt class="text-neutral-500">{{ t('admin.payroll.tabs.overtime') }}</dt><dd>{{ hoursLabel(overtime.normal) }} / {{ hoursLabel(overtime.rest_day) }} / {{ hoursLabel(overtime.holiday) }}</dd></template>
          <template v-if="attendance">
            <dt class="text-neutral-500">{{ t('admin.payroll.deductionRules.absence') }} · {{ t('admin.payroll.deductionRules.unpaidLeave') }}</dt>
            <dd>{{ t('admin.payroll.deductionRules.daysN', { count: attendance.absent_days ?? 0 }) }} · {{ t('admin.payroll.deductionRules.daysN', { count: attendance.unpaid_leave_days ?? 0 }) }}</dd>
            <dt class="text-neutral-500">{{ t('admin.payroll.deductionRules.late') }} · {{ t('admin.payroll.deductionRules.earlyLeave') }}</dt>
            <dd>{{ t('admin.payroll.deductionRules.timesMinutes', { times: attendance.late_times ?? 0, minutes: attendance.late_minutes ?? 0 }) }} · {{ t('admin.payroll.deductionRules.timesMinutes', { times: attendance.early_leave_times ?? 0, minutes: attendance.early_leave_minutes ?? 0 }) }}</dd>
          </template>
          <template v-if="slip.details?.taxed && slip.details.tax">
            <dt class="text-neutral-500">{{ t('admin.payroll.tax.taxBase') }}</dt><dd>{{ riel(slip.details.tax.base) }}</dd>
            <dt class="text-neutral-500">{{ t('admin.payroll.tabs.tax') }}</dt><dd>{{ riel(slip.details.tax.tax) }}</dd>
          </template>
          <template v-else><dt class="text-neutral-500">{{ t('admin.payroll.tabs.tax') }}</dt><dd>{{ t('admin.payroll.payslip.taxInSecondHalf') }}</dd></template>
          <template v-if="slip.details?.khr_per_usd"><dt class="text-neutral-500">{{ t('admin.payroll.payslip.rate') }}</dt><dd>1 USD = {{ slip.details.khr_per_usd.toLocaleString() }} ៛</dd></template>
        </dl>
      </details>
    </template>
    <template #footer>
      <BaseButton variant="outline" @click="emit('close')">{{ t('common.close') }}</BaseButton>
      <BaseButton v-if="slip" @click="printPayslips([slip], site.info)">{{ t('admin.payroll.payslip.print') }}</BaseButton>
    </template>
  </BaseModal>
</template>
