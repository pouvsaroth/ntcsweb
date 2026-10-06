<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import InterviewEvaluationFormModal from '@/components/admin/InterviewEvaluationFormModal.vue'
import InterviewFormModal from '@/components/admin/InterviewFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { applicantsService, type Applicant } from '@/services/applicants'
import {
  INTERVIEW_STATUS_VARIANT,
  INTERVIEW_STATUSES,
  interviewEvaluationsService,
  interviewsService,
  type Interview,
  type InterviewEvaluation,
  type Interviewer,
  type InterviewStatus,
} from '@/services/interviews'
import { jobPositionsService, type JobPosition } from '@/services/jobPositions'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDateTime } from '@/utils/date'

/**
 * HRM > Recruitment > Interview — scheduled interviews, upcoming first.
 * An interviewer evaluates straight from here once it's happened. Cards on
 * a phone, a table from `sm` up.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const canCreate = computed(() => auth.can('recruitment.create'))
const canUpdate = computed(() => auth.can('recruitment.update'))
const canDelete = computed(() => auth.can('recruitment.delete'))

const when = ref<'upcoming' | 'past' | ''>('upcoming')
const jobFilter = ref('')
const statusFilter = ref('')

const { items, meta, loading, error, setPage, setFilter, fetch } = usePaginatedResource<Interview>((query) =>
  interviewsService.list(query, {
    ...(when.value ? { when: when.value } : {}),
    ...(jobFilter.value ? { job_position_id: Number(jobFilter.value) } : {}),
  }),
)

const jobs = ref<JobPosition[]>([])
const applicants = ref<Applicant[]>([])
const interviewers = ref<Interviewer[]>([])

const whenOptions = computed(() => [
  { value: 'upcoming', label: t('admin.recruitment.interviews.upcoming') },
  { value: 'past', label: t('admin.recruitment.interviews.past') },
  { value: '', label: t('admin.recruitment.interviews.all') },
])
const jobFilterOptions = computed(() => [
  { value: '', label: t('admin.recruitment.postings.allJobs') },
  ...jobs.value.map((job) => ({ value: String(job.id), label: `${job.reference} · ${job.title}` })),
])
const statusFilterOptions = computed(() => [
  { value: '', label: t('admin.recruitment.manpower.allStatuses') },
  ...INTERVIEW_STATUSES.map((status) => ({ value: status, label: t(`admin.recruitment.interviewStatuses.${status}`) })),
])

function onWhen(value: string) {
  when.value = value as typeof when.value
  void fetch()
}

function onJob(value: string) {
  jobFilter.value = value
  void fetch()
}

function onStatus(value: string) {
  statusFilter.value = value
  setFilter('status', value || undefined)
}

const columns = computed(() => [
  { key: 'when', label: t('admin.recruitment.interviews.when') },
  { key: 'applicant', label: t('admin.recruitment.applicants.name') },
  { key: 'mode', label: t('admin.recruitment.interviews.mode') },
  { key: 'interviewers', label: t('admin.recruitment.interviews.interviewers') },
  { key: 'evaluations', label: t('admin.recruitment.tabs.evaluations') },
  { key: 'status', label: t('admin.recruitment.interviews.status') },
  { key: 'actions', label: t('admin.recruitment.manpower.actions'), align: 'text-right' },
])

function names(interview: Interview): string {
  return (interview.interviewers ?? []).map((u) => u.name).join(', ') || '—'
}

function mayEvaluate(interview: Interview): boolean {
  return (interview.is_my_interview ?? false) && interview.status !== 'cancelled'
}

// --- Schedule / edit -----------------------------------------------------------

const formOpen = ref(false)
const editing = ref<Interview | null>(null)
const actionError = ref<string | null>(null)

async function loadPickers() {
  if (applicants.value.length === 0) applicants.value = await applicantsService.listAll().catch(() => [])
  if (interviewers.value.length === 0) interviewers.value = await interviewsService.interviewers().catch(() => [])
}

async function openCreate() {
  editing.value = null
  await loadPickers()
  formOpen.value = true
}

async function openEdit(interview: Interview) {
  editing.value = interview
  await loadPickers()
  formOpen.value = true
}

async function setStatus(interview: Interview, status: InterviewStatus) {
  actionError.value = null
  try {
    await interviewsService.update(interview.id, { status })
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.recruitment.interviews.saveFailed')
  }
}

async function remove(interview: Interview) {
  if (!(await confirmDialog.confirm({ message: t('admin.recruitment.interviews.deleteConfirm'), danger: true }))) return
  actionError.value = null
  try {
    await interviewsService.remove(interview.id)
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.recruitment.manpower.deleteFailed')
  }
}

// --- Evaluate ------------------------------------------------------------------

const evaluationOpen = ref(false)
const evaluating = ref<Interview | null>(null)
const existingEvaluation = ref<InterviewEvaluation | null>(null)

async function openEvaluation(interview: Interview) {
  evaluating.value = interview
  existingEvaluation.value = null
  if (interview.my_evaluation_id && auth.user) {
    const mine = await interviewEvaluationsService
      .list({ page: 1, per_page: 1, filter: { interview_id: String(interview.id), evaluator_id: String(auth.user.id) } })
      .catch(() => null)
    existingEvaluation.value = mine?.data[0] ?? null
  }
  evaluationOpen.value = true
}

onMounted(async () => {
  void fetch()
  jobs.value = await jobPositionsService.listAll().catch(() => [])
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <BaseSelect class="w-36" :model-value="when" :options="whenOptions" @update:model-value="onWhen" />
      <BaseSelect class="w-full max-w-60" :model-value="jobFilter" :options="jobFilterOptions" @update:model-value="onJob" />
      <BaseSelect class="w-40" :model-value="statusFilter" :options="statusFilterOptions" @update:model-value="onStatus" />
      <BaseButton v-if="canCreate" class="ml-auto" @click="openCreate">{{ t('admin.recruitment.interviews.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error || actionError" variant="danger" class="mb-4">{{ error || actionError }}</BaseAlert>

    <!-- Cards on a phone (below sm) -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.recruitment.interviews.empty') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="text-sm font-semibold text-neutral-800">{{ formatDateTime(row.scheduled_at) }}</p>
              <p class="truncate text-sm text-neutral-700">{{ row.applicant?.name }}</p>
              <p class="truncate text-xs text-neutral-500">{{ row.applicant?.job_title ?? t('admin.recruitment.applicants.generalApplication') }} · {{ t('admin.recruitment.interviews.roundN', { round: row.round }) }}</p>
            </div>
            <BaseBadge :variant="INTERVIEW_STATUS_VARIANT[row.status]" class="shrink-0">{{ t(`admin.recruitment.interviewStatuses.${row.status}`) }}</BaseBadge>
          </div>
          <p class="mt-2 text-xs text-neutral-600">{{ t(`admin.recruitment.interviewModes.${row.mode}`) }}<template v-if="row.location"> · {{ row.location }}</template></p>
          <p class="text-xs text-neutral-500">{{ names(row) }}</p>
          <p v-if="row.evaluations_count" class="text-xs text-neutral-500">{{ t('admin.recruitment.interviews.evaluated', { count: row.evaluations_count, score: row.average_score ?? '—' }) }}</p>
          <div class="mt-2 flex flex-wrap items-center justify-end gap-2">
            <BaseButton v-if="mayEvaluate(row)" size="sm" :variant="row.my_evaluation_id ? 'outline' : 'primary'" @click="openEvaluation(row)">
              {{ row.my_evaluation_id ? t('admin.recruitment.interviews.reviseEvaluation') : t('admin.recruitment.interviews.evaluate') }}
            </BaseButton>
            <BaseButton v-if="canUpdate && row.status === 'scheduled'" size="sm" variant="outline" @click="setStatus(row, 'completed')">{{ t('admin.recruitment.interviews.markDone') }}</BaseButton>
            <EditIconButton v-if="canUpdate" @click="openEdit(row)" />
            <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600" @click="remove(row)">{{ t('admin.recruitment.manpower.delete') }}</button>
          </div>
        </div>
      </div>
    </div>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.recruitment.interviews.empty')">
        <template #cell-when="{ row }">
          <p class="font-medium text-neutral-800">{{ formatDateTime(row.scheduled_at) }}</p>
          <p class="text-xs text-neutral-500">{{ t('admin.recruitment.interviews.minutesN', { minutes: row.duration_minutes }) }}</p>
        </template>
        <template #cell-applicant="{ row }">
          <p>{{ row.applicant?.name }}</p>
          <p class="text-xs text-neutral-500">{{ row.applicant?.job_title ?? t('admin.recruitment.applicants.generalApplication') }} · {{ t('admin.recruitment.interviews.roundN', { round: row.round }) }}</p>
        </template>
        <template #cell-mode="{ row }">
          <p>{{ t(`admin.recruitment.interviewModes.${row.mode}`) }}</p>
          <a v-if="row.location && row.location.startsWith('http')" :href="row.location" target="_blank" rel="noopener" class="block max-w-48 truncate text-xs text-primary-700 hover:underline">{{ row.location }}</a>
          <p v-else-if="row.location" class="max-w-48 truncate text-xs text-neutral-500">{{ row.location }}</p>
        </template>
        <template #cell-interviewers="{ row }"><span class="text-sm">{{ names(row as Interview) }}</span></template>
        <template #cell-evaluations="{ row }">
          <template v-if="row.evaluations_count">{{ t('admin.recruitment.interviews.evaluated', { count: row.evaluations_count, score: row.average_score ?? '—' }) }}</template>
          <span v-else class="text-neutral-400">—</span>
        </template>
        <template #cell-status="{ row }">
          <BaseBadge :variant="INTERVIEW_STATUS_VARIANT[row.status as InterviewStatus]">{{ t(`admin.recruitment.interviewStatuses.${row.status}`) }}</BaseBadge>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex items-center justify-end gap-2">
            <BaseButton v-if="mayEvaluate(row as Interview)" size="sm" :variant="row.my_evaluation_id ? 'outline' : 'primary'" @click="openEvaluation(row as Interview)">
              {{ row.my_evaluation_id ? t('admin.recruitment.interviews.reviseEvaluation') : t('admin.recruitment.interviews.evaluate') }}
            </BaseButton>
            <BaseButton v-if="canUpdate && row.status === 'scheduled'" size="sm" variant="outline" @click="setStatus(row as Interview, 'completed')">
              {{ t('admin.recruitment.interviews.markDone') }}
            </BaseButton>
            <EditIconButton v-if="canUpdate" @click="openEdit(row as Interview)" />
            <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row as Interview)">
              {{ t('admin.recruitment.manpower.delete') }}
            </button>
          </div>
        </template>
      </DataTable>
    </div>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <InterviewFormModal v-model="formOpen" :interview="editing" :applicants="applicants" :interviewers="interviewers" @saved="fetch" />
    <InterviewEvaluationFormModal v-model="evaluationOpen" :interview="evaluating" :existing="existingEvaluation" @saved="fetch" />
  </div>
</template>
