<script setup lang="ts">
import { onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { runStatusVariant } from '@/pages/admin/payroll/runStatus'
import { payAmountLabel, payrollRunsService, runPeriodLabel, type PayrollRun } from '@/services/payroll'
import { formatDate } from '@/utils/date'

/** Payroll runs in some statuses (and year) — a click opens the run. Cards on a phone, a table from `sm` up. */
const props = defineProps<{ statuses: string; year?: number; empty: string }>()

const { t, locale } = useI18n()
const router = useRouter()

const { items, meta, loading, error, setPage, fetch } = usePaginatedResource<PayrollRun>((query) =>
  payrollRunsService.list(query, { statuses: props.statuses, year: props.year }),
)

watch(() => [props.statuses, props.year], () => setPage(1))

function open(run: PayrollRun) {
  void router.push(`/admin/payroll/runs/${run.id}`)
}

const net = (run: PayrollRun) => run.totals.map((total) => payAmountLabel(total.net_pay, 'fixed', total.currency)).join(' + ') || '—'
const staffCount = (run: PayrollRun) => run.totals.reduce((sum, total) => sum + total.staff_count, 0)

defineExpose({ fetch })
onMounted(() => fetch())
</script>

<template>
  <div>
    <BaseAlert v-if="error" variant="danger" class="mb-3">{{ error }}</BaseAlert>
    <div v-if="loading" class="flex justify-center py-8"><BaseSpinner /></div>
    <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-8 text-center text-sm text-neutral-500">{{ empty }}</p>
    <template v-else>
      <div class="space-y-2 sm:hidden">
        <button
          v-for="run in items"
          :key="run.id"
          type="button"
          class="block w-full rounded-[--radius-card] border border-neutral-200 bg-white p-3 text-left shadow-[--shadow-card]"
          @click="open(run)"
        >
          <div class="flex items-start justify-between gap-2">
            <p class="text-sm font-semibold text-neutral-800">{{ run.reference }} <span class="font-normal text-neutral-500">· {{ runPeriodLabel(run, locale) }}</span></p>
            <BaseBadge :variant="runStatusVariant[run.status]">{{ t(`admin.payroll.runs.status.${run.status}`) }}</BaseBadge>
          </div>
          <p class="text-sm text-neutral-700">{{ t('admin.payroll.payslip.net') }}: <span class="font-semibold">{{ net(run) }}</span></p>
          <p class="text-xs text-neutral-500">{{ t('admin.payroll.runs.staffN', { count: staffCount(run) }) }} · {{ t('admin.payroll.payslip.payDate') }} {{ formatDate(run.pay_date) }}</p>
        </button>
      </div>
      <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block">
        <table class="w-full text-left text-sm">
          <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-500">
            <tr>
              <th class="px-4 py-3 font-medium">{{ t('admin.payroll.payslip.reference') }}</th>
              <th class="px-4 py-3 font-medium">{{ t('admin.payroll.payslip.period') }}</th>
              <th class="px-4 py-3 font-medium">{{ t('admin.payroll.payslip.payDate') }}</th>
              <th class="px-4 py-3 text-right font-medium">{{ t('admin.payroll.staff') }}</th>
              <th class="px-4 py-3 text-right font-medium">{{ t('admin.payroll.payslip.net') }}</th>
              <th class="px-4 py-3 font-medium">{{ t('admin.organization.status') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="run in items" :key="run.id" class="cursor-pointer hover:bg-neutral-50" @click="open(run)">
              <td class="px-4 py-3 font-medium text-primary-700">{{ run.reference }}</td>
              <td class="px-4 py-3 text-neutral-700">{{ runPeriodLabel(run, locale) }}</td>
              <td class="px-4 py-3 text-neutral-700">{{ formatDate(run.pay_date) }}</td>
              <td class="px-4 py-3 text-right tabular-nums">{{ staffCount(run) }}</td>
              <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ net(run) }}</td>
              <td class="px-4 py-3">
                <BaseBadge :variant="runStatusVariant[run.status]">{{ t(`admin.payroll.runs.status.${run.status}`) }}</BaseBadge>
                <p v-if="run.status === 'pending' && run.approval_flow" class="text-xs text-neutral-500">{{ t('admin.approvals.flowStep', { step: run.approval_flow.step, total: run.approval_flow.total, group: run.approval_flow.group ?? '—' }) }}</p>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
    <BasePagination v-if="meta" :meta="meta" class="mt-3" @update:page="setPage" />
  </div>
</template>
