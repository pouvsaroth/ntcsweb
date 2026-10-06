<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { formatDays, leaveReportsService, leaveTypesService, type LeaveReport, type LeaveType } from '@/services/leaveManagement'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'
import { exportTableAsImage } from '@/utils/tableImage'

/**
 * HRM > Leave Management > Leave reports — a year's approved staff leave:
 * totals, days by leave type, by month, and by staff member (most first),
 * which exports as an image like the other reports. Requests still waiting
 * are counted separately. Lists become cards on a phone.
 */
const { t, locale } = useI18n()

const thisYear = new Date().getFullYear()
const year = ref(thisYear)
const yearOptions = [thisYear - 3, thisYear - 2, thisYear - 1, thisYear, thisYear + 1].map((y) => ({ value: String(y), label: String(y) }))
const typeFilter = ref('')
const leaveTypes = ref<LeaveType[]>([])
const typeOptions = computed(() => [
  { value: '', label: t('admin.leaveManagement.policies.allTypes') },
  ...leaveTypes.value.map((type) => ({ value: String(type.id), label: type.name })),
])

const report = ref<LeaveReport | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    report.value = await leaveReportsService.report(year.value, typeFilter.value ? Number(typeFilter.value) : null)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.leaveManagement.saveFailed')
  } finally {
    loading.value = false
  }
}

watch([year, typeFilter], () => void load())

const types = computed(() => report.value?.by_type.map((row) => row.leave_type) ?? [])
const monthName = (month: number) => new Date(2024, month - 1, 1).toLocaleDateString(locale.value, { month: 'short' })
const cell = (days: number | undefined) => (days ? formatDays(days) : '—')

// --- Export ---------------------------------------------------------------------------

const exporting = ref(false)

async function exportStaff() {
  if (!report.value) return
  exporting.value = true
  error.value = null
  try {
    const typeName = leaveTypes.value.find((type) => String(type.id) === typeFilter.value)?.name
    await exportTableAsImage({
      title: `${t('admin.leaveManagement.reports.title')} ${year.value}`,
      subtitle: [typeName ?? t('admin.leaveManagement.policies.allTypes'), `${t('admin.attendance.exportedOn')} ${formatDate(new Date())}`].join('  ·  '),
      columns: [
        { label: t('admin.leaveManagement.requests.staff'), width: 260 },
        ...types.value.map((type) => ({ label: type.name, align: 'right' as const, width: 120 })),
        { label: t('admin.leaveManagement.reports.total'), align: 'right' as const, width: 100 },
      ],
      rows: report.value.by_staff.map((row) => [
        { text: row.staff.name, subtext: row.staff.employee_code, bold: true },
        ...types.value.map((type) => ({ text: cell(row.by_type[type.id]) })),
        { text: formatDays(row.days), bold: true },
      ]),
      emptyText: t('admin.leaveManagement.reports.empty'),
      fileName: `leave-report-${year.value}.png`,
    })
  } catch {
    error.value = t('admin.attendance.exportImageFailed')
  } finally {
    exporting.value = false
  }
}

onMounted(async () => {
  void load()
  leaveTypes.value = await leaveTypesService.listAll().catch(() => [])
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <BaseSelect class="w-28" :model-value="String(year)" :options="yearOptions" @update:model-value="year = Number($event)" />
      <BaseSelect class="w-full sm:w-48" :model-value="typeFilter" :options="typeOptions" @update:model-value="typeFilter = $event" />
      <BaseButton class="ml-auto" variant="outline" :loading="exporting" :disabled="!report || report.by_staff.length === 0" @click="exportStaff">
        {{ t('admin.attendance.exportImage') }}
      </BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <div v-if="loading && !report" class="flex justify-center py-10"><BaseSpinner /></div>

    <template v-else-if="report">
      <!-- Totals -->
      <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-[--radius-card] border border-neutral-200 bg-white p-4">
          <p class="text-xs text-neutral-500">{{ t('admin.leaveManagement.reports.daysTaken') }}</p>
          <p class="mt-1 text-2xl font-semibold tabular-nums text-neutral-900">{{ formatDays(report.totals.days) }}</p>
        </div>
        <div class="rounded-[--radius-card] border border-neutral-200 bg-white p-4">
          <p class="text-xs text-neutral-500">{{ t('admin.leaveManagement.reports.requests') }}</p>
          <p class="mt-1 text-2xl font-semibold tabular-nums text-neutral-900">{{ report.totals.requests }}</p>
        </div>
        <div class="rounded-[--radius-card] border border-neutral-200 bg-white p-4">
          <p class="text-xs text-neutral-500">{{ t('admin.leaveManagement.reports.staffOnLeave') }}</p>
          <p class="mt-1 text-2xl font-semibold tabular-nums text-neutral-900">{{ report.totals.staff }}</p>
        </div>
        <div class="rounded-[--radius-card] border border-neutral-200 bg-white p-4">
          <p class="text-xs text-neutral-500">{{ t('admin.leaveManagement.reports.waiting') }}</p>
          <p class="mt-1 text-2xl font-semibold tabular-nums text-neutral-900">{{ report.totals.pending_requests }}</p>
          <p class="text-xs text-neutral-500">{{ t('admin.leaveManagement.requests.daysN', { days: formatDays(report.totals.pending_days) }) }}</p>
        </div>
      </div>

      <p v-if="report.by_staff.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.leaveManagement.reports.empty') }}
      </p>

      <template v-else>
        <div class="grid gap-6 lg:grid-cols-2">
          <!-- By type -->
          <section>
            <h3 class="mb-2 text-sm font-semibold text-neutral-800">{{ t('admin.leaveManagement.reports.byType') }}</h3>
            <div class="overflow-hidden rounded-[--radius-card] border border-neutral-200 bg-white">
              <table class="w-full text-left text-sm">
                <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-600">
                  <tr>
                    <th class="px-3 py-2 font-medium">{{ t('admin.leaveManagement.policies.leaveType') }}</th>
                    <th class="px-3 py-2 text-right font-medium">{{ t('admin.leaveManagement.requests.days') }}</th>
                    <th class="hidden px-3 py-2 text-right font-medium sm:table-cell">{{ t('admin.leaveManagement.reports.requests') }}</th>
                    <th class="px-3 py-2 text-right font-medium">{{ t('admin.leaveManagement.requests.staff') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                  <tr v-for="row in report.by_type" :key="row.leave_type.id">
                    <td class="px-3 py-2">
                      <span class="inline-flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: row.leave_type.color ?? '#9ca3af' }" />{{ row.leave_type.name }}</span>
                    </td>
                    <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ formatDays(row.days) }}</td>
                    <td class="hidden px-3 py-2 text-right tabular-nums sm:table-cell">{{ row.requests }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ row.staff }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <!-- By month -->
          <section>
            <h3 class="mb-2 text-sm font-semibold text-neutral-800">{{ t('admin.leaveManagement.reports.byMonth') }}</h3>
            <div class="overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white">
              <table class="w-full text-left text-sm">
                <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-600">
                  <tr>
                    <th class="px-3 py-2 font-medium">{{ t('admin.leaveManagement.reports.month') }}</th>
                    <th v-for="type in types" :key="type.id" class="hidden px-3 py-2 text-right font-medium sm:table-cell">{{ type.name }}</th>
                    <th class="px-3 py-2 text-right font-medium">{{ t('admin.leaveManagement.reports.total') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                  <tr v-for="row in report.by_month" :key="row.month" :class="row.days === 0 ? 'text-neutral-400' : ''">
                    <td class="px-3 py-1.5">{{ monthName(row.month) }}</td>
                    <td v-for="type in types" :key="type.id" class="hidden px-3 py-1.5 text-right tabular-nums sm:table-cell">{{ cell(row.by_type[type.id]) }}</td>
                    <td class="px-3 py-1.5 text-right font-semibold tabular-nums">{{ cell(row.days) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
        </div>

        <!-- By staff -->
        <section class="mt-6">
          <h3 class="mb-2 text-sm font-semibold text-neutral-800">{{ t('admin.leaveManagement.reports.byStaff') }}</h3>

          <div class="space-y-2 sm:hidden">
            <div v-for="row in report.by_staff" :key="row.staff.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
              <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                  <p class="truncate text-sm font-semibold text-neutral-800">{{ row.staff.name }}</p>
                  <p class="text-xs text-neutral-500">{{ row.staff.employee_code }}</p>
                </div>
                <p class="shrink-0 text-lg font-semibold tabular-nums text-neutral-900">{{ formatDays(row.days) }}</p>
              </div>
              <div class="mt-2 flex flex-wrap gap-2">
                <template v-for="type in types" :key="type.id">
                  <span v-if="row.by_type[type.id]" class="inline-flex items-center gap-1.5 rounded-full bg-neutral-100 px-2.5 py-1 text-xs text-neutral-700">
                    <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: type.color ?? '#9ca3af' }" />{{ type.name }}: {{ formatDays(row.by_type[type.id]!) }}
                  </span>
                </template>
              </div>
            </div>
          </div>

          <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block">
            <table class="w-full min-w-max text-left text-sm">
              <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-600">
                <tr>
                  <th class="px-4 py-3 font-medium">{{ t('admin.leaveManagement.requests.staff') }}</th>
                  <th v-for="type in types" :key="type.id" class="px-4 py-3 text-right font-medium">{{ type.name }}</th>
                  <th class="px-4 py-3 text-right font-medium">{{ t('admin.leaveManagement.reports.total') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="row in report.by_staff" :key="row.staff.id">
                  <td class="px-4 py-2.5">
                    <p class="font-medium text-neutral-800">{{ row.staff.name }}</p>
                    <p class="text-xs text-neutral-500">{{ row.staff.employee_code }}</p>
                  </td>
                  <td v-for="type in types" :key="type.id" class="px-4 py-2.5 text-right tabular-nums">{{ cell(row.by_type[type.id]) }}</td>
                  <td class="px-4 py-2.5 text-right font-semibold tabular-nums">{{ formatDays(row.days) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </template>
    </template>
  </div>
</template>
