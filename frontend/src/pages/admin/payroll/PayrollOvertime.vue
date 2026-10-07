<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import PeriodPicker from '@/pages/admin/payroll/PeriodPicker.vue'
import {
  currentMonth,
  hoursLabel,
  payAmountLabel,
  payrollPeriodService,
  payrollRulesService,
  type OvertimeRow,
  type PayPeriod,
  type PayrollSettings,
} from '@/services/payroll'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * HRM > Payroll > Overtime — how approved overtime requests (Attendance &
 * Time) are paid: hours × the hourly rate (basic salary ÷ working days ÷
 * hours a day) × the multiplier for a normal day, a rest day or a holiday;
 * and what that comes to for a pay period. Cards on a phone, a table from
 * `sm` up.
 */
const { t } = useI18n()
const auth = useAuthStore()
const canManage = computed(() => auth.can('payroll.manage'))

const settings = ref<PayrollSettings | null>(null)
const month = ref(currentMonth())
const period = ref<PayPeriod>('month')
const rows = ref<OvertimeRow[]>([])
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
    const result = await payrollPeriodService.overtime(month.value, period.value)
    rows.value = result.rows
    range.value = { from: result.from, to: result.to }
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.payroll.loadFailed')
  } finally {
    loading.value = false
  }
}

watch([month, period], () => void loadRows())

function amount(row: OvertimeRow): string {
  return row.amount === null ? t('admin.payroll.salaries.notSet') : payAmountLabel(row.amount, 'fixed', row.currency)
}

/** Totals per currency — USD and riel are never added together. */
const totals = computed(() => {
  const sums = new Map<string, number>()
  for (const row of rows.value) {
    if (row.amount === null || row.currency === null) continue
    sums.set(row.currency, (sums.get(row.currency) ?? 0) + row.amount)
  }
  return [...sums.entries()].map(([currency, sum]) => payAmountLabel(sum, 'fixed', currency as 'USD' | 'KHR'))
})

// --- Rules form ---------------------------------------------------------------------

const formOpen = ref(false)
const form = reactive({ working_days_per_month: '', hours_per_day: '', overtime_normal_rate: '', overtime_rest_day_rate: '', overtime_holiday_rate: '' })
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

function openForm() {
  if (!settings.value) return
  form.working_days_per_month = String(settings.value.working_days_per_month)
  form.hours_per_day = String(settings.value.hours_per_day)
  form.overtime_normal_rate = String(settings.value.overtime_normal_rate)
  form.overtime_rest_day_rate = String(settings.value.overtime_rest_day_rate)
  form.overtime_holiday_rate = String(settings.value.overtime_holiday_rate)
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
        working_days_per_month: Number(form.working_days_per_month),
        hours_per_day: Number(form.hours_per_day),
        overtime_normal_rate: Number(form.overtime_normal_rate),
        overtime_rest_day_rate: Number(form.overtime_rest_day_rate),
        overtime_holiday_rate: Number(form.overtime_holiday_rate),
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

const percent = (rate: number) => `${Math.round(rate * 100)}%`

onMounted(() => {
  void loadSettings()
  void loadRows()
})
</script>

<template>
  <div>
    <!-- The rules -->
    <section class="mb-6 rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
      <div class="mb-3 flex items-start justify-between gap-3">
        <div>
          <h2 class="text-sm font-semibold text-neutral-800">{{ t('admin.payroll.overtime.rules') }}</h2>
          <p class="text-sm text-neutral-500">{{ t('admin.payroll.overtime.rulesHint') }}</p>
        </div>
        <BaseButton v-if="canManage && settings" size="sm" variant="outline" @click="openForm">{{ t('admin.payroll.edit') }}</BaseButton>
      </div>
      <dl v-if="settings" class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-5">
        <div><dt class="text-neutral-500">{{ t('admin.payroll.overtime.workingDays') }}</dt><dd class="font-semibold text-neutral-900">{{ settings.working_days_per_month }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.payroll.overtime.hoursPerDay') }}</dt><dd class="font-semibold text-neutral-900">{{ settings.hours_per_day }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.payroll.overtime.normalDay') }}</dt><dd class="font-semibold text-neutral-900">{{ percent(settings.overtime_normal_rate) }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.payroll.overtime.restDay') }}</dt><dd class="font-semibold text-neutral-900">{{ percent(settings.overtime_rest_day_rate) }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.payroll.overtime.holiday') }}</dt><dd class="font-semibold text-neutral-900">{{ percent(settings.overtime_holiday_rate) }}</dd></div>
      </dl>
    </section>

    <!-- A pay period -->
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <PeriodPicker v-model:month="month" v-model:period="period" />
      <p v-if="range" class="text-sm text-neutral-500">
        {{ formatDate(range.from) }} – {{ formatDate(range.to) }}<template v-if="totals.length"> · {{ t('admin.payroll.total') }}: <span class="font-semibold text-neutral-900">{{ totals.join(' + ') }}</span></template>
      </p>
    </div>
    <p class="mb-4 text-sm text-neutral-500">{{ t('admin.payroll.overtime.periodHint') }}</p>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
    <p v-else-if="rows.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
      {{ t('admin.payroll.overtime.empty') }}
    </p>
    <template v-else>
      <div class="space-y-2 sm:hidden">
        <div v-for="row in rows" :key="row.staff.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <p class="text-sm font-semibold text-neutral-800">{{ row.staff.name }} <span class="font-normal text-neutral-500">({{ row.staff.employee_code }})</span></p>
            <span class="shrink-0 text-sm font-semibold text-neutral-900">{{ amount(row) }}</span>
          </div>
          <p class="text-xs text-neutral-600">
            {{ t('admin.payroll.overtime.normalDay') }} {{ hoursLabel(row.minutes.normal) }} · {{ t('admin.payroll.overtime.restDay') }} {{ hoursLabel(row.minutes.rest_day) }} · {{ t('admin.payroll.overtime.holiday') }} {{ hoursLabel(row.minutes.holiday) }}
          </p>
        </div>
      </div>
      <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block">
        <table class="w-full text-left text-sm">
          <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-500">
            <tr>
              <th class="px-4 py-3 font-medium">{{ t('admin.payroll.staff') }}</th>
              <th class="px-4 py-3 text-right font-medium">{{ t('admin.payroll.overtime.normalDay') }}</th>
              <th class="px-4 py-3 text-right font-medium">{{ t('admin.payroll.overtime.restDay') }}</th>
              <th class="px-4 py-3 text-right font-medium">{{ t('admin.payroll.overtime.holiday') }}</th>
              <th class="px-4 py-3 text-right font-medium">{{ t('admin.payroll.overtime.totalHours') }}</th>
              <th class="px-4 py-3 text-right font-medium">{{ t('admin.payroll.amount') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="row in rows" :key="row.staff.id">
              <td class="px-4 py-3">
                <p class="font-medium text-neutral-800">{{ row.staff.name }}</p>
                <p class="text-xs text-neutral-500">{{ row.staff.employee_code }}</p>
              </td>
              <td class="px-4 py-3 text-right tabular-nums text-neutral-700">{{ hoursLabel(row.minutes.normal) }}</td>
              <td class="px-4 py-3 text-right tabular-nums text-neutral-700">{{ hoursLabel(row.minutes.rest_day) }}</td>
              <td class="px-4 py-3 text-right tabular-nums text-neutral-700">{{ hoursLabel(row.minutes.holiday) }}</td>
              <td class="px-4 py-3 text-right tabular-nums text-neutral-700">{{ hoursLabel(row.total_minutes) }}</td>
              <td class="px-4 py-3 text-right font-semibold tabular-nums text-neutral-900">{{ amount(row) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <BaseModal v-model="formOpen" :title="t('admin.payroll.overtime.rules')">
      <form class="space-y-4" @submit.prevent="save">
        <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput v-model="form.working_days_per_month" type="number" required :label="t('admin.payroll.overtime.workingDays')" :hint="t('admin.payroll.overtime.workingDaysHint')" :error="errors.working_days_per_month?.[0]" />
          <BaseInput v-model="form.hours_per_day" type="number" required :label="t('admin.payroll.overtime.hoursPerDay')" :error="errors.hours_per_day?.[0]" />
        </div>
        <p class="text-sm text-neutral-600">{{ t('admin.payroll.overtime.multiplierHint') }}</p>
        <div class="grid gap-4 sm:grid-cols-3">
          <BaseInput v-model="form.overtime_normal_rate" type="number" required :label="t('admin.payroll.overtime.normalDay')" :error="errors.overtime_normal_rate?.[0]" />
          <BaseInput v-model="form.overtime_rest_day_rate" type="number" required :label="t('admin.payroll.overtime.restDay')" :error="errors.overtime_rest_day_rate?.[0]" />
          <BaseInput v-model="form.overtime_holiday_rate" type="number" required :label="t('admin.payroll.overtime.holiday')" :error="errors.overtime_holiday_rate?.[0]" />
        </div>
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="formOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
