<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import PayslipModal from '@/pages/admin/payroll/PayslipModal.vue'
import { payAmountLabel, payrollRunsService, runPeriodLabel, type PayrollRun, type Payslip } from '@/services/payroll'
import { useSiteStore } from '@/stores/site'
import { ApiRequestError } from '@/types/api'
import { printPayslips } from '@/utils/printPayslip'

/**
 * HRM > Payroll > Payslip — pick a payroll, then see, print or save as PDF
 * any staff member's payslip (or all of them at once). Tick staff and
 * Export PDF downloads one PDF of them, signed by whoever prepared and
 * approved the payroll (see PayslipPdfService). The latest payroll is
 * picked to start with. Cards on a phone, a table from `sm` up.
 */
const { t, locale } = useI18n()
const site = useSiteStore()

const runs = ref<PayrollRun[]>([])
const runId = ref('')
const slips = ref<Payslip[]>([])
const search = ref('')
const loading = ref(false)
const printing = ref(false)
const error = ref<string | null>(null)
const viewing = ref<number | null>(null)

// Ticked payslips for Export PDF.
const selected = ref<number[]>([])
const exporting = ref(false)
const allTicked = computed(() => visible.value.length > 0 && visible.value.every((s) => selected.value.includes(s.id)))
function toggle(id: number) {
  selected.value = selected.value.includes(id) ? selected.value.filter((x) => x !== id) : [...selected.value, id]
}
function toggleAll() {
  const ids = visible.value.map((s) => s.id)
  selected.value = allTicked.value ? selected.value.filter((id) => !ids.includes(id)) : [...new Set([...selected.value, ...ids])]
}

async function exportPdf() {
  const run = runs.value.find((r) => String(r.id) === runId.value)
  if (!run || selected.value.length === 0) return
  exporting.value = true
  error.value = null
  try {
    await payrollRunsService.payslipsPdf(run.id, selected.value, locale.value, `payslips-${run.reference}.pdf`)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.payroll.payslip.exportFailed')
  } finally {
    exporting.value = false
  }
}

const runOptions = computed(() =>
  runs.value.map((run) => ({ value: String(run.id), label: `${run.reference} · ${runPeriodLabel(run, locale.value)} · ${t(`admin.payroll.runs.status.${run.status}`)}` })),
)

const visible = computed(() => {
  const term = search.value.trim().toLowerCase()
  return term ? slips.value.filter((s) => `${s.staff?.name ?? ''} ${s.staff?.employee_code ?? ''}`.toLowerCase().includes(term)) : slips.value
})

async function loadRuns() {
  try {
    runs.value = (await payrollRunsService.list({ page: 1, per_page: 100, filter: {} }, { statuses: 'paid,approved,pending,draft,rejected' })).data
    runId.value = runs.value[0] ? String(runs.value[0].id) : ''
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.payroll.loadFailed')
  }
}

watch(runId, async (id) => {
  slips.value = []
  selected.value = []
  if (!id) return
  loading.value = true
  error.value = null
  try {
    slips.value = (await payrollRunsService.get(Number(id))).payslips ?? []
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.payroll.loadFailed')
  } finally {
    loading.value = false
  }
})

async function printAll() {
  printing.value = true
  try {
    printPayslips(await Promise.all(visible.value.map((slip) => payrollRunsService.payslip(slip.id))), site.info)
  } finally {
    printing.value = false
  }
}

const money = (slip: Payslip, amount: number) => payAmountLabel(amount, 'fixed', slip.currency)

onMounted(() => loadRuns())
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-3">
      <BaseSelect v-model="runId" class="w-full sm:w-96" :options="runOptions" :placeholder="t('admin.payroll.payslip.pickRun')" />
      <input
        v-model="search"
        type="search"
        :placeholder="t('common.searchPlaceholder')"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200 sm:max-w-xs"
      />
      <div v-if="visible.length" class="flex w-full flex-wrap gap-2 sm:ml-auto sm:w-auto">
        <BaseButton variant="outline" :loading="printing" @click="printAll">{{ t('admin.payroll.runs.printAll') }}</BaseButton>
        <BaseButton :loading="exporting" :disabled="selected.length === 0" @click="exportPdf">{{ t('admin.payroll.payslip.exportPdf', { count: selected.length }) }}</BaseButton>
      </div>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <p v-if="!loading && runs.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('admin.payroll.payslip.noRuns') }}</p>
    <div v-else-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
    <template v-else-if="visible.length">
      <div class="space-y-2 sm:hidden">
        <label class="flex items-center gap-2 px-1 text-sm text-neutral-600">
          <input type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" :checked="allTicked" @change="toggleAll" />
          {{ t('admin.payroll.payslip.selectAll') }}
        </label>
        <div v-for="slip in visible" :key="slip.id" class="flex items-center gap-3 rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <input type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" :checked="selected.includes(slip.id)" :aria-label="slip.staff?.name" @change="toggle(slip.id)" />
          <button type="button" class="flex min-w-0 flex-1 items-center justify-between gap-2 text-left" @click="viewing = slip.id">
            <span class="text-sm font-semibold text-neutral-800">{{ slip.staff?.name }} <span class="font-normal text-neutral-500">({{ slip.staff?.employee_code }})</span></span>
            <span class="shrink-0 text-sm font-bold">{{ money(slip, slip.net_pay) }}</span>
          </button>
        </div>
      </div>
      <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block">
        <table class="w-full text-left text-sm">
          <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-500">
            <tr>
              <th class="w-10 px-4 py-3">
                <input type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" :checked="allTicked" :aria-label="t('admin.payroll.payslip.selectAll')" @change="toggleAll" />
              </th>
              <th class="px-4 py-3 font-medium">{{ t('admin.payroll.staff') }}</th>
              <th class="px-4 py-3 text-right font-medium">{{ t('admin.payroll.payslip.gross') }}</th>
              <th class="px-4 py-3 text-right font-medium">{{ t('admin.payroll.payslip.totalDeductions') }}</th>
              <th class="px-4 py-3 text-right font-medium">{{ t('admin.payroll.payslip.net') }}</th>
              <th class="px-4 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="slip in visible" :key="slip.id">
              <td class="px-4 py-3">
                <input type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" :checked="selected.includes(slip.id)" :aria-label="slip.staff?.name" @change="toggle(slip.id)" />
              </td>
              <td class="px-4 py-3">
                <p class="font-medium text-neutral-800">{{ slip.staff?.name }}</p>
                <p class="text-xs text-neutral-500">{{ slip.staff?.employee_code }}</p>
              </td>
              <td class="px-4 py-3 text-right tabular-nums">{{ money(slip, slip.gross_pay) }}</td>
              <td class="px-4 py-3 text-right tabular-nums">{{ money(slip, slip.lines.deductions.reduce((sum, l) => sum + l.amount, 0)) }}</td>
              <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ money(slip, slip.net_pay) }}</td>
              <td class="px-4 py-3 text-right"><button type="button" class="text-sm font-medium text-primary-700 hover:underline" @click="viewing = slip.id">{{ t('admin.payroll.payslip.view') }}</button></td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
    <p v-else-if="runId" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('admin.payroll.runs.noPayslips') }}</p>

    <PayslipModal :payslip-id="viewing" @close="viewing = null" />
  </div>
</template>
