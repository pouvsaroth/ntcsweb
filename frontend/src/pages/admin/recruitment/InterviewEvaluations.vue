<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import InterviewEvaluationFormModal from '@/components/admin/InterviewEvaluationFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import {
  EVALUATION_CRITERIA,
  interviewEvaluationsService,
  interviewsService,
  RECOMMENDATION_VARIANT,
  RECOMMENDATIONS,
  type Interview,
  type InterviewEvaluation,
  type Recommendation,
} from '@/services/interviews'
import { jobPositionsService, type JobPosition } from '@/services/jobPositions'
import { formatDateTime } from '@/utils/date'

/**
 * HRM > Recruitment > Interview evaluation: first the interviews waiting on
 * the signed-in user's evaluation, then every evaluation, by job and
 * recommendation. Cards on a phone, a table from `sm` up.
 */
const { t } = useI18n()

const jobFilter = ref('')
const recommendationFilter = ref('')

const { items, meta, loading, error, setPage, setFilter, fetch } = usePaginatedResource<InterviewEvaluation>((query) =>
  interviewEvaluationsService.list(query, jobFilter.value ? { job_position_id: Number(jobFilter.value) } : {}),
)

const jobs = ref<JobPosition[]>([])
const waiting = ref<Interview[]>([])

const jobFilterOptions = computed(() => [
  { value: '', label: t('admin.recruitment.postings.allJobs') },
  ...jobs.value.map((job) => ({ value: String(job.id), label: `${job.reference} · ${job.title}` })),
])
const recommendationOptions = computed(() => [
  { value: '', label: t('admin.recruitment.evaluations.allRecommendations') },
  ...RECOMMENDATIONS.map((r) => ({ value: r, label: t(`admin.recruitment.recommendations.${r}`) })),
])

function onJob(value: string) {
  jobFilter.value = value
  void fetch()
}

function onRecommendation(value: string) {
  recommendationFilter.value = value
  setFilter('recommendation', value || undefined)
}

const columns = computed(() => [
  { key: 'applicant', label: t('admin.recruitment.applicants.name') },
  { key: 'evaluator', label: t('admin.recruitment.evaluations.evaluator') },
  { key: 'overall', label: t('admin.recruitment.evaluations.overall') },
  { key: 'recommendation', label: t('admin.recruitment.evaluations.recommendation') },
  { key: 'created_at', label: t('admin.recruitment.evaluations.date') },
])

// --- Evaluate one that's waiting ---------------------------------------------

const formOpen = ref(false)
const evaluating = ref<Interview | null>(null)

function evaluate(interview: Interview) {
  evaluating.value = interview
  formOpen.value = true
}

async function reload() {
  void fetch()
  waiting.value = await interviewsService.awaitingMyEvaluation().catch(() => [])
}

// --- Read one ------------------------------------------------------------------

const viewing = ref<InterviewEvaluation | null>(null)

onMounted(async () => {
  void reload()
  jobs.value = await jobPositionsService.listAll().catch(() => [])
})
</script>

<template>
  <div>
    <!-- Waiting for my evaluation -->
    <section v-if="waiting.length > 0" class="mb-6 rounded-[--radius-card] border border-primary-200 bg-primary-50/40 p-4">
      <h2 class="mb-3 text-sm font-semibold text-neutral-900">{{ t('admin.recruitment.evaluations.waiting', { count: waiting.length }) }}</h2>
      <ul class="space-y-2">
        <li v-for="interview in waiting" :key="interview.id" class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-white px-3 py-2">
          <div class="min-w-0">
            <p class="truncate text-sm font-medium text-neutral-800">{{ interview.applicant?.name }}</p>
            <p class="truncate text-xs text-neutral-500">
              {{ interview.applicant?.job_title ?? t('admin.recruitment.applicants.generalApplication') }} ·
              {{ t('admin.recruitment.interviews.roundN', { round: interview.round }) }} · {{ formatDateTime(interview.scheduled_at) }}
            </p>
          </div>
          <BaseButton size="sm" @click="evaluate(interview)">{{ t('admin.recruitment.interviews.evaluate') }}</BaseButton>
        </li>
      </ul>
    </section>

    <div class="mb-4 flex flex-wrap items-center gap-2">
      <BaseSelect class="w-full max-w-60" :model-value="jobFilter" :options="jobFilterOptions" @update:model-value="onJob" />
      <BaseSelect class="w-44" :model-value="recommendationFilter" :options="recommendationOptions" @update:model-value="onRecommendation" />
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <!-- Cards on a phone (below sm) -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.recruitment.evaluations.empty') }}
      </p>
      <div v-else class="space-y-2">
        <button
          v-for="row in items"
          :key="row.id"
          type="button"
          class="block w-full rounded-[--radius-card] border border-neutral-200 bg-white p-3 text-left shadow-[--shadow-card]"
          @click="viewing = row"
        >
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate text-sm font-semibold text-neutral-800">{{ row.interview?.applicant?.name }}</p>
              <p class="truncate text-xs text-neutral-500">{{ row.interview?.applicant?.job_title }} · {{ t('admin.recruitment.interviews.roundN', { round: row.interview?.round ?? 1 }) }}</p>
            </div>
            <BaseBadge :variant="RECOMMENDATION_VARIANT[row.recommendation]" class="shrink-0">{{ t(`admin.recruitment.recommendations.${row.recommendation}`) }}</BaseBadge>
          </div>
          <p class="mt-2 text-xs text-neutral-600">{{ row.evaluator }} · <span class="font-semibold">{{ row.overall_score.toFixed(2) }}</span> / 5</p>
        </button>
      </div>
    </div>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.recruitment.evaluations.empty')">
        <template #cell-applicant="{ row }">
          <button type="button" class="text-left" @click="viewing = row as InterviewEvaluation">
            <p class="font-medium text-primary-700 hover:underline">{{ row.interview?.applicant?.name }}</p>
            <p class="text-xs text-neutral-500">{{ row.interview?.applicant?.job_title }} · {{ t('admin.recruitment.interviews.roundN', { round: row.interview?.round ?? 1 }) }}</p>
          </button>
        </template>
        <template #cell-evaluator="{ row }">{{ row.evaluator ?? '—' }}</template>
        <template #cell-overall="{ row }"><span class="font-semibold">{{ (row as InterviewEvaluation).overall_score.toFixed(2) }}</span> / 5</template>
        <template #cell-recommendation="{ row }">
          <BaseBadge :variant="RECOMMENDATION_VARIANT[row.recommendation as Recommendation]">{{ t(`admin.recruitment.recommendations.${row.recommendation}`) }}</BaseBadge>
        </template>
        <template #cell-created_at="{ row }">{{ formatDateTime(row.updated_at) }}</template>
      </DataTable>
    </div>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <BaseModal :model-value="viewing !== null" :title="viewing?.interview?.applicant?.name ?? ''" @update:model-value="viewing = null">
      <div v-if="viewing" class="space-y-4 text-sm">
        <p class="text-neutral-600">
          {{ viewing.evaluator }} · {{ t('admin.recruitment.interviews.roundN', { round: viewing.interview?.round ?? 1 }) }} · {{ formatDateTime(viewing.updated_at) }}
        </p>
        <dl class="space-y-1.5">
          <div v-for="criterion in EVALUATION_CRITERIA" :key="criterion" class="flex items-center justify-between">
            <dt class="text-neutral-600">{{ t(`admin.recruitment.criteria.${criterion}`) }}</dt>
            <dd class="font-semibold text-neutral-900">{{ viewing.scores[criterion] }} / 5</dd>
          </div>
          <div class="flex items-center justify-between border-t border-neutral-100 pt-1.5">
            <dt class="font-medium text-neutral-800">{{ t('admin.recruitment.evaluations.overall') }}</dt>
            <dd class="font-semibold text-neutral-900">{{ viewing.overall_score.toFixed(2) }} / 5</dd>
          </div>
        </dl>
        <p>
          <BaseBadge :variant="RECOMMENDATION_VARIANT[viewing.recommendation]">{{ t(`admin.recruitment.recommendations.${viewing.recommendation}`) }}</BaseBadge>
        </p>
        <div v-if="viewing.strengths">
          <p class="font-medium text-neutral-800">{{ t('admin.recruitment.evaluations.strengths') }}</p>
          <p class="whitespace-pre-line text-neutral-700">{{ viewing.strengths }}</p>
        </div>
        <div v-if="viewing.concerns">
          <p class="font-medium text-neutral-800">{{ t('admin.recruitment.evaluations.concerns') }}</p>
          <p class="whitespace-pre-line text-neutral-700">{{ viewing.concerns }}</p>
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="viewing = null">{{ t('common.close') }}</BaseButton>
      </template>
    </BaseModal>

    <InterviewEvaluationFormModal v-model="formOpen" :interview="evaluating" @saved="reload" />
  </div>
</template>
