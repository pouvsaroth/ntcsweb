<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import PeriodPicker from '@/pages/admin/payroll/PeriodPicker.vue'
import {
  currentMonth,
  payAmountLabel,
  payrollPeriodService,
  payrollRulesService,
  type DeductionRow,
  type LateDeductionMode,
  type PayPeriod,
  type PayrollSettings,
} from '@/services/payroll'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * HRM > Payroll > Attendance deduction — what Attendance & Time's records take
 * off pay: absent days and unpaid leave days at the daily rate, and late /
 * early-leave minutes past the grace period, per minute at the hourly rate
 * or a fixed amount each time. Lists only staff with something to deduct
 * in the pay period. Cards on a phone, a table from `sm` up.
 */
const { t } = useI18n()
const auth = useAuthStore()
const canManage = computed(() => auth.can('payroll.manage'))

const settings = ref<PayrollSettings | null>(null)
const month = ref(currentMonth())
const period = ref<PayPeriod>('month')
const rows = ref<DeductionRow[]>([])
const range = ref<{ from: string; to: string } | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

async function loadSettings() {
  try {
    settings.value = (await payrollRulesService.get()).settings
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.payroll.loadFailed')
  }
}

async function loadRows() {
  if (!month.value) return
  loading.value = true
  error.value = null
  try {
    const result = await payrollPeriodService.deductions(month.value, period.value)
    rows.value = result.rows
    range.value = { from: result.from, to: result.to }
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.payroll.loadFailed')
  } finally {
    loading.value = false
  }
}

watch([month, period], () => void loadRows())

function money(row: DeductionRow, value: number | undefined): string {
  if (row.lines === null) return t('admin.payroll.salaries.notSet')
  return payAmountLabel(value ?? 0, 'fixed', row.currency)
}

const totals = computed(() => {
  const sums = new Map<string, number>()
  for (const row of rows.value) {
    if (row.amount === null || row.currency === null) continue
    sums.set(row.currency, (sums.get(row.currency) ?? 0) + row.amount)
  }
  return [...sums.entries()].map(([currency, sum]) => payAmountLabel(sum, 'fixed', currency as 'USD' | 'KHR'))
})

/** "Per minute past 10 min grace" — the late rule in one line. */
const lateRule = computed(() => {
  const s = settings.value
  if (!s) return ''
  if (s.late_deduction_mode === 'none') return t('admin.payroll.deductionRules.notDeducted')
  const grace = t('admin.payroll.deductionRules.graceN', { minutes: s.late_grace_minutes })
  if (s.late_deduction_mode === 'per_minute') return `${t('admin.payroll.deductionRules.perMinute')} · ${grace}`
  return `${payAmountLabel(s.late_amount_usd, 'fixed', 'USD')} / ${payAmountLabel(s.late_amount_khr, 'fixed', 'KHR')} ${t('admin.payroll.deductionRules.eachTime')} · ${grace}`
})

const yesNo = (value: boolean) => (value ? t('admin.payroll.yes') : t('admin.payroll.no'))

// --- Rules form ---------------------------------------------------------------------

const modeOptions = computed(() => [
  { value: 'none', label: t('admin.payroll.deductionRules.notDeducted') },
  { value: 'per_minute', label: t('admin.payroll.deductionRules.perMinute') },
  { value: 'per_occurrence', label: t('admin.payroll.deductionRules.perOccurrence') },
])

const formOpen = ref(false)
const form = reactive({
  deduct_absence: true,
  deduct_unpaid_leave: true,
  late_deduction_mode: 'per_minute' as LateDeductionMode,
  late_grace_minutes: '',
  late_amount_usd: '',
  late_amount_khr: '',
  deduct_early_leave: true,
})
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

function openForm() {
  const s = settings.value
  if (!s) return
  form.deduct_absence = s.deduct_absence
  form.deduct_unpaid_leave = s.deduct_unpaid_leave
  form.late_deduction_mode = s.late_deduction_mode
  form.late_grace_minutes = String(s.late_grace_minutes)
  form.late_amount_usd = String(s.late_amount_usd)
  form.late_amount_khr = String(s.late_amount_khr)
  form.deduct_early_leave = s.deduct_early_leave
  errors.value = {}
  saveError.value = null
  formOpen.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  try {
    settings.value = (
      await payrollRulesService.updateSettings({
        deduct_absence: form.deduct_absence,
        deduct_unpaid_leave: form.deduct_unpaid_leave,
        late_deduction_mode: form.late_deduction_mode,
        late_grace_minutes: Number(form.late_grace_minutes || 0),
        late_amount_usd: Number(form.late_amount_usd || 0),
        late_amount_khr: Number(form.late_amount_khr || 0),
        deduct_early_leave: form.deduct_early_leave,
      })
    ).settings
    formOpen.value = false
    await loadRows()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.payroll.saveFailed')
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  void loadSettings()
  void loadRows()
})
</script>

<template>
  <div>
    <section class="mb-6 rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
      <div class="mb-3 flex items-start justify-between gap-3">
        <div>
          <h2 class="text-sm font-semibold text-neutral-800">{{ t('admin.payroll.deductionRules.title') }}</h2>
          <p class="text-sm text-neutral-500">{{ t('admin.payroll.deductionRules.hint') }}</p>
        </div>
        <BaseButton v-if="canManage && settings" size="sm" variant="outline" @click="openForm">{{ t('admin.payroll.edit') }}</BaseButton>
      </div>
      <dl v-if="settings" class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-4">
        <div><dt class="text-neutral-500">{{ t('admin.payroll.deductionRules.absence') }}</dt><dd class="font-semibold text-neutral-900">{{ yesNo(settings.deduct_absence) }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.payroll.deductionRules.unpaidLeave') }}</dt><dd class="font-semibold text-neutral-900">{{ yesNo(settings.deduct_unpaid_leave) }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.payroll.deductionRules.late') }}</dt><dd class="font-semibold text-neutral-900">{{ lateRule }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.payroll.deductionRules.earlyLeave') }}</dt><dd class="font-semibold text-neutral-900">{{ settings.deduct_early_leave ? t('admin.payroll.deductionRules.sameAsLate') : t('admin.payroll.no') }}</dd></div>
      </dl>
    </section>

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <PeriodPicker v-model:month="month" v-model:period="period" />
      <p v-if="range" class="text-sm text-neutral-500">
        {{ formatDate(range.from) }} – {{ formatDate(range.to) }}<template v-if="totals.length"> · {{ t('admin.payroll.total') }}: <span class="font-semibold text-neutral-900">{{ totals.join(' + ') }}</span></template>
      </p>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
    <p v-else-if="rows.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
      {{ t('admin.payroll.deductionRules.empty') }}
    </p>
    <template v-else>
      <div class="space-y-2 sm:hidden">
        <div v-for="row in rows" :key="row.staff.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <p class="text-sm font-semibold text-neutral-800">{{ row.staff.name }} <span class="font-normal text-neutral-500">({{ row.staff.employee_code }})</span></p>
            <span class="shrink-0 text-sm font-semibold text-danger-600">{{ money(row, row.amount ?? 0) }}</span>
          </div>
          <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
            <dt class="text-neutral-500">{{ t('admin.payroll.deductionRules.absence') }}</dt><dd class="text-neutral-800">{{ t('admin.payroll.deductionRules.daysN', { count: row.absent_days }) }}</dd>
            <dt class="text-neutral-500">{{ t('admin.payroll.deductionRules.unpaidLeave') }}</dt><dd class="text-neutral-800">{{ t('admin.payroll.deductionRules.daysN', { count: row.unpaid_leave_days }) }}</dd>
            <dt class="text-neutral-500">{{ t('admin.payroll.deductionRules.late') }}</dt><dd class="text-neutral-800">{{ t('admin.payroll.deductionRules.timesMinutes', { times: row.late_times, minutes: row.late_minutes }) }}</dd>
            <dt class="text-neutral-500">{{ t('admin.payroll.deductionRules.earlyLeave') }}</dt><dd class="text-neutral-800">{{ t('admin.payroll.deductionRules.timesMinutes', { times: row.early_leave_times, minutes: row.early_leave_minutes }) }}</dd>
          </dl>
        </div>
      </div>
      <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block">
        <table class="w-full text-left text-sm">
          <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-500">
            <tr>
              <th class="px-4 py-3 font-medium">{{ t('admin.payroll.staff') }}</th>
              <th class="px-4 py-3 text-right font-medium">{{ t('admin.payroll.deductionRules.absence') }}</th>
              <th class="px-4 py-3 text-right font-medium">{{ t('admin.payroll.deductionRules.unpaidLeave') }}</th>
              <th class="px-4 py-3 text-right font-medium">{{ t('admin.payroll.deductionRules.late') }}</th>
              <th class="px-4 py-3 text-right font-medium">{{ t('admin.payroll.deductionRules.earlyLeave') }}</th>
              <th class="px-4 py-3 text-right font-medium">{{ t('admin.payroll.total') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="row in rows" :key="row.staff.id">
              <td class="px-4 py-3">
                <p class="font-medium text-neutral-800">{{ row.staff.name }}</p>
                <p class="text-xs text-neutral-500">{{ row.staff.employee_code }}</p>
              </td>
              <td class="px-4 py-3 text-right tabular-nums">
                <p class="text-neutral-800">{{ money(row, row.lines?.absence) }}</p>
                <p class="text-xs text-neutral-500">{{ t('admin.payroll.deductionRules.daysN', { count: row.absent_days }) }}</p>
              </td>
              <td class="px-4 py-3 text-right tabular-nums">
                <p class="text-neutral-800">{{ money(row, row.lines?.unpaid_leave) }}</p>
                <p class="text-xs text-neutral-500">{{ t('admin.payroll.deductionRules.daysN', { count: row.unpaid_leave_days }) }}</p>
              </td>
              <td class="px-4 py-3 text-right tabular-nums">
                <p class="text-neutral-800">{{ money(row, row.lines?.late) }}</p>
                <p class="text-xs text-neutral-500">{{ t('admin.payroll.deductionRules.timesMinutes', { times: row.late_times, minutes: row.late_minutes }) }}</p>
              </td>
              <td class="px-4 py-3 text-right tabular-nums">
                <p class="text-neutral-800">{{ money(row, row.lines?.early_leave) }}</p>
                <p class="text-xs text-neutral-500">{{ t('admin.payroll.deductionRules.timesMinutes', { times: row.early_leave_times, minutes: row.early_leave_minutes }) }}</p>
              </td>
              <td class="px-4 py-3 text-right font-semibold tabular-nums text-danger-600">{{ money(row, row.amount ?? 0) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <BaseModal v-model="formOpen" :title="t('admin.payroll.deductionRules.title')">
      <form class="space-y-4" @submit.prevent="save">
        <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
        <div class="space-y-2">
          <label class="flex items-center gap-2 text-sm text-neutral-700">
            <input v-model="form.deduct_absence" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
            {{ t('admin.payroll.deductionRules.deductAbsence') }}
          </label>
          <label class="flex items-center gap-2 text-sm text-neutral-700">
            <input v-model="form.deduct_unpaid_leave" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
            {{ t('admin.payroll.deductionRules.deductUnpaidLeave') }}
          </label>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseSelect
            :model-value="form.late_deduction_mode"
            :options="modeOptions"
            :label="t('admin.payroll.deductionRules.lateMode')"
            :error="errors.late_deduction_mode?.[0]"
            @update:model-value="form.late_deduction_mode = $event as LateDeductionMode"
          />
          <BaseInput v-model="form.late_grace_minutes" type="number" :label="t('admin.payroll.deductionRules.grace')" :hint="t('admin.payroll.deductionRules.graceHint')" :error="errors.late_grace_minutes?.[0]" />
        </div>
        <div v-if="form.late_deduction_mode === 'per_occurrence'" class="grid gap-4 sm:grid-cols-2">
          <BaseInput v-model="form.late_amount_usd" type="number" :label="t('admin.payroll.deductionRules.amountUsd')" :error="errors.late_amount_usd?.[0]" />
          <BaseInput v-model="form.late_amount_khr" type="number" :label="t('admin.payroll.deductionRules.amountKhr')" :error="errors.late_amount_khr?.[0]" />
        </div>
        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="form.deduct_early_leave" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
          {{ t('admin.payroll.deductionRules.deductEarlyLeave') }}
        </label>
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="formOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
