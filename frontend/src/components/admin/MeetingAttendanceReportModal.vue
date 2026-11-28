<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { meetingsService, type MeetingAttendanceReport } from '@/services/meetings'
import { ApiRequestError } from '@/types/api'

/**
 * Front Office > Meeting arrangement > Attendance report: per staff member,
 * how many meetings in the range they were present, late, absent or excused
 * at (cancelled meetings left out). Excel adds every meeting with each
 * person's mark, guests included.
 */
const props = defineProps<{ modelValue: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [boolean] }>()

const { t } = useI18n()

const pad = (n: number) => String(n).padStart(2, '0')
const iso = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
const now = new Date()
const from = ref(iso(new Date(now.getFullYear(), now.getMonth(), 1)))
const to = ref(iso(new Date(now.getFullYear(), now.getMonth() + 1, 0)))

const report = ref<MeetingAttendanceReport | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const exporting = ref(false)

const rangeOk = computed(() => Boolean(from.value && to.value && from.value <= to.value))

async function load() {
  if (!rangeOk.value) return
  loading.value = true
  error.value = null
  try {
    report.value = await meetingsService.attendanceReport({ from: from.value, to: to.value })
  } catch (e) {
    report.value = null
    error.value = e instanceof ApiRequestError ? e.message : t('frontOffice.meetings.loadFailed')
  } finally {
    loading.value = false
  }
}

async function downloadExcel() {
  exporting.value = true
  error.value = null
  try {
    await meetingsService.downloadAttendanceReport({ from: from.value, to: to.value })
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('frontOffice.meetings.loadFailed')
  } finally {
    exporting.value = false
  }
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) void load()
  },
)
watch([from, to], () => {
  if (props.modelValue) void load()
})

const percent = (p: number | null) => (p === null ? '—' : `${p}%`)
</script>

<template>
  <BaseModal :model-value="modelValue" size="xl" :title="t('frontOffice.meetings.attendanceReport')" @update:model-value="emit('update:modelValue', $event)">
    <div class="space-y-4">
      <div class="flex flex-wrap items-end gap-3">
        <BaseInput v-model="from" type="date" :label="t('frontOffice.meetings.from')" />
        <BaseInput v-model="to" type="date" :label="t('frontOffice.meetings.to')" />
        <BaseButton variant="outline" class="sm:ml-auto" :loading="exporting" :disabled="!rangeOk || exporting || !report?.summary.length" @click="downloadExcel">
          {{ t('frontOffice.meetings.excel') }}
        </BaseButton>
      </div>

      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>
      <div v-if="loading" class="flex justify-center py-8"><BaseSpinner /></div>

      <template v-else-if="report">
        <p class="text-sm text-neutral-500">{{ t('frontOffice.meetings.reportMeetings', { n: report.meetings.length }) }}</p>
        <p v-if="!report.summary.length" class="rounded-lg border border-dashed border-neutral-300 py-8 text-center text-sm text-neutral-500">
          {{ t('frontOffice.meetings.reportEmpty') }}
        </p>

        <template v-else>
          <!-- Phone: one card per person. -->
          <ul class="space-y-2 sm:hidden">
            <li v-for="row in report.summary" :key="row.staff_id" class="rounded-lg border border-neutral-200 p-3">
              <div class="flex items-start justify-between gap-2">
                <p class="font-medium text-neutral-900">{{ row.name }}</p>
                <span class="text-sm font-semibold text-neutral-900">{{ percent(row.attended_percent) }}</span>
              </div>
              <p class="mt-1 text-xs text-neutral-500">
                {{ t('frontOffice.meetings.attendanceCounts', { n: row.present, late: row.late, absent: row.absent, excused: row.excused, none: row.not_marked }) }}
              </p>
              <p class="text-xs text-neutral-500">{{ t('frontOffice.meetings.reportMeetings', { n: row.meetings }) }}</p>
            </li>
          </ul>

          <!-- Desktop: table. -->
          <div class="hidden overflow-x-auto rounded-lg border border-neutral-200 sm:block">
            <table class="min-w-full text-sm">
              <thead class="bg-neutral-50 text-left text-xs font-medium text-neutral-500">
                <tr>
                  <th class="px-3 py-2">{{ t('frontOffice.meetings.reportName') }}</th>
                  <th class="px-3 py-2 text-right">{{ t('frontOffice.meetings.reportMeetingsCol') }}</th>
                  <th class="px-3 py-2 text-right">{{ t('frontOffice.meetings.attendanceStates.present') }}</th>
                  <th class="px-3 py-2 text-right">{{ t('frontOffice.meetings.attendanceStates.late') }}</th>
                  <th class="px-3 py-2 text-right">{{ t('frontOffice.meetings.attendanceStates.absent') }}</th>
                  <th class="px-3 py-2 text-right">{{ t('frontOffice.meetings.attendanceStates.excused') }}</th>
                  <th class="px-3 py-2 text-right">{{ t('frontOffice.meetings.notMarked') }}</th>
                  <th class="px-3 py-2 text-right">{{ t('frontOffice.meetings.attendedPercent') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="row in report.summary" :key="row.staff_id">
                  <td class="px-3 py-2 text-neutral-900">
                    {{ row.name }}
                    <span v-if="row.employee_code" class="text-xs text-neutral-500">({{ row.employee_code }})</span>
                  </td>
                  <td class="px-3 py-2 text-right">{{ row.meetings }}</td>
                  <td class="px-3 py-2 text-right text-success-600">{{ row.present }}</td>
                  <td class="px-3 py-2 text-right text-warning-600">{{ row.late }}</td>
                  <td class="px-3 py-2 text-right text-danger-600">{{ row.absent }}</td>
                  <td class="px-3 py-2 text-right text-primary-700">{{ row.excused }}</td>
                  <td class="px-3 py-2 text-right text-neutral-500">{{ row.not_marked }}</td>
                  <td class="px-3 py-2 text-right font-semibold">{{ percent(row.attended_percent) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p class="text-xs text-neutral-500">{{ t('frontOffice.meetings.attendedHint') }}</p>
        </template>
      </template>
    </div>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
    </template>
  </BaseModal>
</template>
