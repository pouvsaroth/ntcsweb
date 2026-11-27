<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import {
  CLASS_SESSION_STATUSES,
  teachersService,
  type ClassSession,
  type ClassSessionQuery,
  type ClassSessionStatus,
  type ClassSessionSummary,
  type Teacher,
  type TeacherOptions,
} from '@/services/teachers'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * HRM > Teacher > Class sessions: every scheduled class session in a period
 * and what happened to it — taught, covered, not held, missed or no record —
 * with totals per teacher. Worked out on the server (ClassSessionReport).
 */
const { t } = useI18n()

const pad = (n: number) => String(n).padStart(2, '0')
const iso = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
const now = new Date()
const from = ref(iso(new Date(now.getFullYear(), now.getMonth(), 1)))
const to = ref(iso(now))
const staffId = ref('')
const classId = ref('')
const status = ref('')

const teachers = ref<Teacher[]>([])
const options = ref<TeacherOptions>({ classes: [], subjects: [], academic_years: [] })
const rows = ref<ClassSession[]>([])
const summary = ref<ClassSessionSummary[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const exporting = ref<'excel' | 'pdf' | null>(null)

const teacherOptions = computed(() => [{ value: '', label: t('teacherMgmt.allTeachers') }, ...teachers.value.map((x) => ({ value: String(x.id), label: x.name }))])
const classOptions = computed(() => [{ value: '', label: t('teacherMgmt.allClasses') }, ...options.value.classes.map((c) => ({ value: String(c.id), label: c.name }))])
const statusOptions = computed(() => [
  { value: '', label: t('teacherMgmt.sessions.allStatuses') },
  ...CLASS_SESSION_STATUSES.map((s) => ({ value: s, label: t(`teacherMgmt.sessions.status.${s}`) })),
])

const query = computed<ClassSessionQuery>(() => ({
  from: from.value,
  to: to.value,
  staff_id: staffId.value ? Number(staffId.value) : undefined,
  class_id: classId.value ? Number(classId.value) : undefined,
  status: (status.value || undefined) as ClassSessionStatus | undefined,
}))
const rangeOk = computed(() => Boolean(from.value && to.value && from.value <= to.value))

async function load() {
  if (!rangeOk.value) return
  loading.value = true
  error.value = null
  try {
    const data = await teachersService.sessions(query.value)
    rows.value = data.rows
    summary.value = data.summary
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('teacherMgmt.loadFailed')
  } finally {
    loading.value = false
  }
}
watch(query, () => void load())

async function download(format: 'excel' | 'pdf') {
  exporting.value = format
  error.value = null
  try {
    await teachersService.downloadSessions(format, query.value)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('teacherMgmt.loadFailed')
  } finally {
    exporting.value = null
  }
}

const tone: Record<ClassSessionStatus, 'success' | 'primary' | 'neutral' | 'warning' | 'danger'> = {
  taught: 'success',
  covering: 'primary',
  covered: 'neutral',
  not_held: 'warning',
  no_record: 'warning',
  missed: 'danger',
}
function detail(r: ClassSession): string {
  const parts: string[] = []
  if (r.makeup) parts.push(t('teacherMgmt.sessions.makeup'))
  if (r.status === 'covered') parts.push(t('teacherMgmt.sessions.coveredBy', { name: r.other_teacher ?? '—' }))
  else if (r.status === 'covering') parts.push(t('teacherMgmt.sessions.coveringFor', { name: r.other_teacher ?? '—' }))
  else if (r.why) parts.push(t(`teacherMgmt.sessions.why.${r.why}`))
  else if (r.status === 'no_record') parts.push(t('teacherMgmt.sessions.noRecordHint'))
  if (r.late_minutes > 0) parts.push(t('teacherMgmt.sessions.lateMin', { n: r.late_minutes }))
  return parts.join(' · ')
}
const hours = (minutes: number) => Math.round((minutes / 60) * 100) / 100
const percent = (s: ClassSessionSummary) => (s.taught_percent === null ? '—' : `${s.taught_percent}%`)
const rowKey = (r: ClassSession) => `${r.date}|${r.start_time}|${r.class_id}|${r.staff_id ?? 0}|${r.status}`
const listRows = computed(() => rows.value.map((r) => ({ ...r, key: rowKey(r) })))

const summaryColumns = computed(() => [
  { key: 'teacher', label: t('teacherMgmt.teacher') },
  { key: 'planned', label: t('teacherMgmt.sessions.planned'), align: 'text-right' },
  { key: 'taught', label: t('teacherMgmt.sessions.taught'), align: 'text-right' },
  { key: 'late', label: t('teacherMgmt.sessions.late'), align: 'text-right' },
  { key: 'covered', label: t('teacherMgmt.sessions.covered'), align: 'text-right' },
  { key: 'not_held', label: t('teacherMgmt.sessions.notHeld'), align: 'text-right' },
  { key: 'missed', label: t('teacherMgmt.sessions.missed'), align: 'text-right' },
  { key: 'no_record', label: t('teacherMgmt.sessions.noRecord'), align: 'text-right' },
  { key: 'covering', label: t('teacherMgmt.sessions.covering'), align: 'text-right' },
  { key: 'hours', label: t('teacherMgmt.sessions.hours'), align: 'text-right' },
  { key: 'percent', label: t('teacherMgmt.sessions.percent'), align: 'text-right' },
])
const listColumns = computed(() => [
  { key: 'date', label: t('teacherMgmt.date') },
  { key: 'class', label: t('teacherMgmt.class') },
  { key: 'teacher', label: t('teacherMgmt.teacher') },
  { key: 'status', label: t('teacherMgmt.sessions.result') },
  { key: 'detail', label: t('teacherMgmt.sessions.detail') },
  { key: 'check_in', label: t('teacherMgmt.sessions.checkIn') },
])

onMounted(async () => {
  const [list, opts] = await Promise.all([teachersService.list().catch(() => []), teachersService.options().catch(() => options.value)])
  teachers.value = list
  options.value = opts
  await load()
})
</script>

<template>
  <div>
    <div class="mb-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
      <BaseInput v-model="from" type="date" :label="t('teacherMgmt.sessions.from')" />
      <BaseInput v-model="to" type="date" :label="t('teacherMgmt.sessions.to')" />
      <BaseSelect v-model="staffId" :options="teacherOptions" :label="t('teacherMgmt.teacher')" />
      <BaseSelect v-model="classId" :options="classOptions" :label="t('teacherMgmt.class')" />
      <BaseSelect v-model="status" :options="statusOptions" :label="t('teacherMgmt.sessions.result')" />
    </div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <p class="text-xs text-neutral-500">{{ t('teacherMgmt.sessions.rangeHint') }}</p>
      <BaseSpinner v-if="loading" class="h-4 w-4" />
      <div class="ml-auto flex gap-2">
        <BaseButton variant="outline" :loading="exporting === 'excel'" :disabled="!rangeOk || exporting !== null" @click="download('excel')">{{ t('teacherMgmt.sessions.excel') }}</BaseButton>
        <BaseButton variant="outline" :loading="exporting === 'pdf'" :disabled="!rangeOk || exporting !== null" @click="download('pdf')">{{ t('teacherMgmt.sessions.pdf') }}</BaseButton>
      </div>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <p class="mb-4 text-sm text-neutral-600">{{ t('teacherMgmt.sessions.hint') }}</p>

    <!-- By teacher -->
    <h3 class="mb-2 text-sm font-semibold text-neutral-800">{{ t('teacherMgmt.sessions.summary') }}</h3>
    <div class="mb-6 space-y-2 sm:hidden">
      <p v-if="!loading && summary.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-8 text-center text-sm text-neutral-500">{{ t('teacherMgmt.sessions.empty') }}</p>
      <div v-for="s in summary" :key="s.staff_id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
        <div class="flex items-center justify-between">
          <p class="text-sm font-semibold text-neutral-800">{{ s.teacher }}</p>
          <p class="text-sm font-semibold text-neutral-800">{{ percent(s) }}</p>
        </div>
        <p class="text-xs text-neutral-500">
          {{ t('teacherMgmt.sessions.planned') }} {{ s.planned }} · {{ t('teacherMgmt.sessions.taught') }} {{ s.taught }} · {{ t('teacherMgmt.sessions.hours') }} {{ hours(s.taught_minutes) }}
        </p>
        <div class="mt-2 flex flex-wrap gap-1">
          <BaseBadge v-if="s.late" variant="warning">{{ t('teacherMgmt.sessions.late') }} {{ s.late }}</BaseBadge>
          <BaseBadge v-if="s.covered" variant="neutral">{{ t('teacherMgmt.sessions.covered') }} {{ s.covered }}</BaseBadge>
          <BaseBadge v-if="s.not_held" variant="warning">{{ t('teacherMgmt.sessions.notHeld') }} {{ s.not_held }}</BaseBadge>
          <BaseBadge v-if="s.missed" variant="danger">{{ t('teacherMgmt.sessions.missed') }} {{ s.missed }}</BaseBadge>
          <BaseBadge v-if="s.no_record" variant="warning">{{ t('teacherMgmt.sessions.noRecord') }} {{ s.no_record }}</BaseBadge>
          <BaseBadge v-if="s.covering" variant="primary">{{ t('teacherMgmt.sessions.covering') }} {{ s.covering }}</BaseBadge>
        </div>
      </div>
    </div>
    <div class="mb-6 hidden sm:block">
      <DataTable :columns="summaryColumns" :rows="summary" row-key="staff_id" :loading="loading" :empty-message="t('teacherMgmt.sessions.empty')">
        <template #cell-teacher="{ row }"><span class="font-medium text-neutral-800">{{ row.teacher }}</span></template>
        <template #cell-late="{ row }">
          <span :class="row.late ? 'text-warning-600' : ''">{{ row.late }}</span>
        </template>
        <template #cell-missed="{ row }">
          <span :class="row.missed ? 'font-semibold text-danger-600' : ''">{{ row.missed }}</span>
        </template>
        <template #cell-no_record="{ row }">
          <span :class="row.no_record ? 'text-warning-600' : ''">{{ row.no_record }}</span>
        </template>
        <template #cell-hours="{ row }">{{ hours((row as ClassSessionSummary).taught_minutes) }}</template>
        <template #cell-percent="{ row }"><span class="font-semibold">{{ percent(row as ClassSessionSummary) }}</span></template>
      </DataTable>
    </div>

    <!-- Sessions -->
    <h3 class="mb-2 text-sm font-semibold text-neutral-800">{{ t('teacherMgmt.sessions.list') }}</h3>
    <div class="space-y-2 sm:hidden">
      <p v-if="!loading && listRows.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-8 text-center text-sm text-neutral-500">{{ t('teacherMgmt.sessions.empty') }}</p>
      <div v-for="row in listRows" :key="row.key" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
        <div class="flex items-start justify-between gap-2">
          <p class="text-sm font-semibold text-neutral-800">{{ row.class }} · {{ formatDate(row.date) }} {{ row.start_time }}–{{ row.end_time }}</p>
          <BaseBadge :variant="tone[row.status]">{{ t(`teacherMgmt.sessions.status.${row.status}`) }}</BaseBadge>
        </div>
        <p class="text-xs text-neutral-500">{{ row.teacher ?? t('teacherMgmt.sessions.noTeacher') }}<template v-if="row.subject"> · {{ row.subject }}</template></p>
        <p v-if="detail(row)" class="text-xs text-neutral-600">{{ detail(row) }}</p>
        <p v-if="row.check_in" class="text-xs text-neutral-500">{{ t('teacherMgmt.sessions.checkIn') }} {{ row.check_in }}</p>
      </div>
    </div>
    <div class="hidden sm:block">
      <DataTable :columns="listColumns" :rows="listRows" row-key="key" :loading="loading" :empty-message="t('teacherMgmt.sessions.empty')">
        <template #cell-date="{ row }">
          <p>{{ formatDate(row.date) }}</p>
          <p class="text-xs text-neutral-500">{{ row.start_time }}–{{ row.end_time }}</p>
        </template>
        <template #cell-class="{ row }">
          <p class="font-medium text-neutral-800">{{ row.class }}</p>
          <p v-if="row.subject" class="text-xs text-neutral-500">{{ row.subject }}</p>
        </template>
        <template #cell-teacher="{ row }">{{ row.teacher ?? t('teacherMgmt.sessions.noTeacher') }}</template>
        <template #cell-status="{ row }">
          <BaseBadge :variant="tone[(row as ClassSession).status]">{{ t(`teacherMgmt.sessions.status.${row.status}`) }}</BaseBadge>
        </template>
        <template #cell-detail="{ row }"><span class="text-neutral-600">{{ detail(row as ClassSession) || '—' }}</span></template>
        <template #cell-check_in="{ row }">{{ row.check_in ?? '—' }}</template>
      </DataTable>
    </div>
  </div>
</template>
