<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import PayslipModal from '@/pages/admin/payroll/PayslipModal.vue'
import RunList from '@/pages/admin/payroll/RunList.vue'
import { runStatusVariant } from '@/pages/admin/payroll/runStatus'
import { payAmountLabel, payrollRunsService, runPeriodLabel, type Payslip } from '@/services/payroll'
import { staffService, type Staff } from '@/services/staff'
import { ApiRequestError } from '@/types/api'

/**
 * HRM > Payroll > Payroll history — a year's paid (and cancelled)
 * payrolls, and any staff member's payslips over time.
 */
const { t, locale } = useI18n()

const thisYear = new Date().getFullYear()
const year = ref(thisYear)
const yearOptions = computed(() => Array.from({ length: 6 }, (_, i) => ({ value: String(thisYear - i), label: String(thisYear - i) })))

// --- One staff member's payslips -----------------------------------------------------------

const staff = ref<Staff[]>([])
const staffId = ref('')
const staffOptions = computed(() => staff.value.map((s) => ({ value: String(s.id), label: s.full_name, hint: s.employee_code })))
const slips = ref<Payslip[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const viewing = ref<number | null>(null)

watch(staffId, async (id) => {
  slips.value = []
  if (!id) return
  loading.value = true
  error.value = null
  try {
    slips.value = (await payrollRunsService.payslips({ page: 1, per_page: 200, filter: { staff_id: id } })).data
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.payroll.loadFailed')
  } finally {
    loading.value = false
  }
})

const money = (slip: Payslip, amount: number) => payAmountLabel(amount, 'fixed', slip.currency)

onMounted(async () => {
  staff.value = await staffService.listAll().catch(() => [])
})
</script>

<template>
  <div class="space-y-8">
    <section>
      <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-sm font-semibold text-neutral-800">{{ t('admin.payroll.history.runs') }}</h2>
        <BaseSelect class="w-28" :model-value="String(year)" :options="yearOptions" @update:model-value="year = Number($event)" />
      </div>
      <RunList statuses="paid,cancelled" :year="year" :empty="t('admin.payroll.history.noRuns')" />
    </section>

    <section>
      <h2 class="text-sm font-semibold text-neutral-800">{{ t('admin.payroll.history.staff') }}</h2>
      <p class="mb-3 text-sm text-neutral-500">{{ t('admin.payroll.history.staffHint') }}</p>
      <SearchableSelect v-model="staffId" class="mb-3 sm:max-w-md" :options="staffOptions" :placeholder="t('admin.payroll.history.pickStaff')" />

      <BaseAlert v-if="error" variant="danger" class="mb-3">{{ error }}</BaseAlert>
      <div v-if="loading" class="flex justify-center py-8"><BaseSpinner /></div>
      <p v-else-if="staffId && slips.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-8 text-center text-sm text-neutral-500">{{ t('admin.payroll.history.noPayslips') }}</p>
      <ul v-else-if="slips.length" class="divide-y divide-neutral-100 rounded-[--radius-card] border border-neutral-200 bg-white">
        <li v-for="slip in slips" :key="slip.id">
          <button type="button" class="flex w-full flex-wrap items-center justify-between gap-2 px-4 py-2.5 text-left hover:bg-neutral-50" @click="viewing = slip.id">
            <span class="min-w-0">
              <span class="text-sm font-medium text-neutral-800">{{ slip.run ? runPeriodLabel(slip.run, locale) : '—' }}</span>
              <span class="ml-2 text-xs text-neutral-500">{{ slip.run?.reference }}</span>
              <BaseBadge v-if="slip.run" :variant="runStatusVariant[slip.run.status]" class="ml-2">{{ t(`admin.payroll.runs.status.${slip.run.status}`) }}</BaseBadge>
            </span>
            <span class="text-sm tabular-nums">
              <span class="text-neutral-500">{{ t('admin.payroll.payslip.gross') }} {{ money(slip, slip.gross_pay) }} · {{ t('admin.payroll.tabs.tax') }} {{ money(slip, slip.tax) }} ·</span>
              <span class="font-semibold text-neutral-900"> {{ t('admin.payroll.payslip.net') }} {{ money(slip, slip.net_pay) }}</span>
            </span>
          </button>
        </li>
      </ul>
    </section>

    <PayslipModal :payslip-id="viewing" @close="viewing = null" />
  </div>
</template>
