<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import PeriodPicker from '@/pages/admin/payroll/PeriodPicker.vue'
import RunList from '@/pages/admin/payroll/RunList.vue'
import { currentMonth, payrollRunsService, type PayPeriod } from '@/services/payroll'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'

/**
 * HRM > Payroll > Payroll calculation — start a payroll for a pay period
 * (a month, or 1st–15th / 16th–end) and work on the ones not paid yet. A
 * new payroll is worked out straight away from everything set up in the
 * other tabs; opening one shows its payslips.
 */
const { t } = useI18n()
const auth = useAuthStore()
const router = useRouter()
const canRun = computed(() => auth.can('payroll.run'))

const formOpen = ref(false)
const form = reactive({ month: currentMonth(), period: 'month' as PayPeriod, pay_date: '', note: '' })
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

function lastDayOf(month: string): string {
  const [y, m] = month.split('-').map(Number)
  const last = new Date(y!, m!, 0)
  return `${last.getFullYear()}-${String(last.getMonth() + 1).padStart(2, '0')}-${String(last.getDate()).padStart(2, '0')}`
}

function open() {
  form.month = currentMonth()
  form.period = 'month'
  form.pay_date = lastDayOf(form.month)
  form.note = ''
  errors.value = {}
  saveError.value = null
  formOpen.value = true
}

async function create() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  try {
    const run = await payrollRunsService.create({ month: form.month, period: form.period, pay_date: form.pay_date, note: form.note.trim() || null })
    formOpen.value = false
    void router.push(`/admin/payroll/runs/${run.id}`)
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    saveError.value = e instanceof ApiRequestError ? e.message : t('admin.payroll.saveFailed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <p class="text-sm text-neutral-500">{{ t('admin.payroll.runs.calculationHint') }}</p>
      <BaseButton v-if="canRun" @click="open">{{ t('admin.payroll.runs.new') }}</BaseButton>
    </div>

    <RunList statuses="draft,rejected,pending,approved" :empty="t('admin.payroll.runs.noneOpen')" />

    <BaseModal v-model="formOpen" :title="t('admin.payroll.runs.new')">
      <form class="space-y-4" @submit.prevent="create">
        <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
        <div>
          <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.payroll.period.label') }}</label>
          <PeriodPicker v-model:month="form.month" v-model:period="form.period" />
          <p v-if="errors.period?.[0]" class="mt-1 text-sm text-danger-600">{{ errors.period[0] }}</p>
        </div>
        <BaseInput v-model="form.pay_date" type="date" required :label="t('admin.payroll.payslip.payDate')" :error="errors.pay_date?.[0]" />
        <BaseInput v-model="form.note" :label="t('admin.payroll.note')" />
        <p class="text-sm text-neutral-500">{{ t('admin.payroll.runs.newHint') }}</p>
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="formOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" @click="create">{{ t('admin.payroll.runs.workOut') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
