<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import ApplicantDetailModal from '@/components/admin/ApplicantDetailModal.vue'
import ConfirmReasonModal from '@/components/admin/ConfirmReasonModal.vue'
import OfferLetterFormModal from '@/components/admin/OfferLetterFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { applicantsService, STAGE_VARIANT, type ApplicantStage } from '@/services/applicants'
import { jobPositionsService, type JobPosition } from '@/services/jobPositions'
import { OFFER_STATUS_VARIANT, offerLettersService, type Candidate, type OfferStatus } from '@/services/offerLetters'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'

/**
 * HRM > Recruitment > Candidate selection: pick a job, see its candidates
 * best-first by interview score and how the interviewers voted, then make
 * an offer to the chosen one(s) or reject the rest. Cards on a phone, a
 * table from `sm` up.
 */
const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()

const canCreate = computed(() => auth.can('recruitment.create'))
const canUpdate = computed(() => auth.can('recruitment.update'))

const jobs = ref<JobPosition[]>([])
const jobId = ref('')
const candidates = ref<Candidate[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

const job = computed(() => jobs.value.find((j) => String(j.id) === jobId.value) ?? null)
const jobOptions = computed(() => jobs.value.map((j) => ({ value: String(j.id), label: `${j.reference} · ${j.title} (${t(`admin.recruitment.jobStatuses.${j.status}`)})` })))

async function load() {
  if (!jobId.value) return
  loading.value = true
  error.value = null
  try {
    candidates.value = await offerLettersService.candidates(Number(jobId.value))
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.recruitment.selection.loadFailed')
  } finally {
    loading.value = false
  }
}

function onJob(value: string) {
  jobId.value = value
  void load()
}

const columns = computed(() => [
  { key: 'rank', label: '#' },
  { key: 'name', label: t('admin.recruitment.applicants.name') },
  { key: 'interviews', label: t('admin.recruitment.tabs.interviews') },
  { key: 'score', label: t('admin.recruitment.evaluations.overall') },
  { key: 'votes', label: t('admin.recruitment.selection.votes') },
  { key: 'stage', label: t('admin.recruitment.applicants.stage') },
  { key: 'actions', label: t('admin.recruitment.manpower.actions'), align: 'text-right' },
])

/** Still in the running — an offer can be made / they can be rejected. */
function isOpen(c: Candidate): boolean {
  return !['hired', 'rejected', 'withdrawn'].includes(c.stage) && (c.offer === null || ['declined', 'withdrawn'].includes(c.offer.status))
}

// --- Make offer ------------------------------------------------------------------

const offerOpen = ref(false)
const offerFor = ref<{ id: number; name: string; jobTitle: string | null; expectedSalary: number | null; departmentId: number | null; employmentType: JobPosition['employment_type'] } | null>(null)

function makeOffer(c: Candidate) {
  offerFor.value = {
    id: c.id,
    name: c.name,
    jobTitle: job.value?.title ?? null,
    expectedSalary: c.expected_salary,
    departmentId: job.value?.department_id ?? null,
    employmentType: job.value?.employment_type ?? 'full_time',
  }
  offerOpen.value = true
}

function onOfferSaved() {
  void router.push('/admin/recruitment/offers')
}

// --- Reject --------------------------------------------------------------------

const rejectOpen = ref(false)
const rejectTarget = ref<Candidate | null>(null)
const rejecting = ref(false)
const rejectError = ref<string | null>(null)

function openReject(c: Candidate) {
  rejectTarget.value = c
  rejectError.value = null
  rejectOpen.value = true
}

async function confirmReject(reason: string) {
  if (!rejectTarget.value) return
  rejecting.value = true
  rejectError.value = null
  try {
    const applicant = await applicantsService.get(rejectTarget.value.id)
    const note = `${t('admin.recruitment.selection.rejectedNote')}: ${reason}`
    await applicantsService.update(applicant.id, { stage: 'rejected', notes: applicant.notes ? `${applicant.notes}\n${note}` : note })
    rejectOpen.value = false
    await load()
  } catch (e) {
    rejectError.value = e instanceof ApiRequestError ? e.message : t('admin.recruitment.applicants.saveFailed')
  } finally {
    rejecting.value = false
  }
}

// --- Details ---------------------------------------------------------------------

const detailOpen = ref(false)
const detailId = ref<number | null>(null)

function openDetail(c: Candidate) {
  detailId.value = c.id
  detailOpen.value = true
}

onMounted(async () => {
  jobs.value = await jobPositionsService.listAll().catch(() => [])
  const first = jobs.value.find((j) => j.status === 'open') ?? jobs.value[0]
  if (first) onJob(String(first.id))
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <BaseSelect class="w-full max-w-md" :model-value="jobId" :options="jobOptions" :placeholder="t('admin.recruitment.selection.pickJob')" @update:model-value="onJob" />
      <p v-if="job" class="text-sm text-neutral-500">{{ t('admin.recruitment.selection.headcount', { count: job.headcount }) }}</p>
    </div>

    <EmptyState v-if="jobs.length === 0 && !loading" :title="t('admin.recruitment.positions.empty')" :message="t('admin.recruitment.postings.noJobsHint')" />

    <template v-else>
      <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

      <!-- Cards on a phone (below sm) -->
      <div class="sm:hidden">
        <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
        <p v-else-if="candidates.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
          {{ t('admin.recruitment.selection.empty') }}
        </p>
        <div v-else class="space-y-2">
          <div v-for="(c, index) in candidates" :key="c.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
            <div class="flex items-start justify-between gap-2">
              <button type="button" class="min-w-0 text-left" @click="openDetail(c)">
                <p class="truncate text-sm font-semibold text-primary-700">{{ index + 1 }}. {{ c.name }}</p>
                <p class="text-xs text-neutral-500">{{ c.phone }}</p>
              </button>
              <BaseBadge :variant="STAGE_VARIANT[c.stage]" class="shrink-0">{{ t(`admin.recruitment.stages.${c.stage}`) }}</BaseBadge>
            </div>
            <p class="mt-2 text-sm text-neutral-800">
              <span class="font-semibold">{{ c.average_score?.toFixed(2) ?? '—' }}</span> / 5 ·
              {{ t('admin.recruitment.selection.voteLine', { hire: c.votes.hire, maybe: c.votes.maybe, no: c.votes.no_hire }) }}
            </p>
            <p class="text-xs text-neutral-500">{{ t('admin.recruitment.selection.interviewCount', { count: c.interviews }) }}</p>
            <p v-if="c.offer" class="mt-1 text-xs">
              <BaseBadge :variant="OFFER_STATUS_VARIANT[c.offer.status]">{{ c.offer.reference }} · {{ t(`admin.recruitment.offerStatuses.${c.offer.status}`) }}</BaseBadge>
            </p>
            <div v-if="isOpen(c) && (canCreate || canUpdate)" class="mt-2 flex justify-end gap-2">
              <BaseButton v-if="canUpdate" size="sm" variant="outline" @click="openReject(c)">{{ t('admin.recruitment.selection.reject') }}</BaseButton>
              <BaseButton v-if="canCreate" size="sm" @click="makeOffer(c)">{{ t('admin.recruitment.selection.makeOffer') }}</BaseButton>
            </div>
          </div>
        </div>
      </div>

      <div class="hidden sm:block">
        <DataTable :columns="columns" :rows="candidates" row-key="id" :loading="loading" :empty-message="t('admin.recruitment.selection.empty')">
          <template #cell-rank="{ row }">{{ candidates.indexOf(row as Candidate) + 1 }}</template>
          <template #cell-name="{ row }">
            <button type="button" class="text-left" @click="openDetail(row as Candidate)">
              <p class="font-medium text-primary-700 hover:underline">{{ row.name }}</p>
              <p class="text-xs text-neutral-500">{{ row.phone }}<template v-if="row.expected_salary"> · {{ t('admin.recruitment.selection.expects', { amount: row.expected_salary }) }}</template></p>
            </button>
          </template>
          <template #cell-interviews="{ row }">{{ row.interviews }}</template>
          <template #cell-score="{ row }">
            <span class="font-semibold">{{ (row as Candidate).average_score?.toFixed(2) ?? '—' }}</span>
            <span class="text-neutral-500"> / 5</span>
            <p class="text-xs text-neutral-500">{{ t('admin.recruitment.selection.evaluationCount', { count: row.evaluations }) }}</p>
          </template>
          <template #cell-votes="{ row }">
            <div class="flex flex-wrap gap-1 text-xs">
              <BaseBadge variant="success">{{ t('admin.recruitment.recommendations.hire') }} {{ row.votes.hire }}</BaseBadge>
              <BaseBadge variant="warning">{{ t('admin.recruitment.recommendations.maybe') }} {{ row.votes.maybe }}</BaseBadge>
              <BaseBadge variant="danger">{{ t('admin.recruitment.recommendations.no_hire') }} {{ row.votes.no_hire }}</BaseBadge>
            </div>
          </template>
          <template #cell-stage="{ row }">
            <BaseBadge :variant="STAGE_VARIANT[row.stage as ApplicantStage]">{{ t(`admin.recruitment.stages.${row.stage}`) }}</BaseBadge>
            <p v-if="row.offer" class="mt-1">
              <BaseBadge :variant="OFFER_STATUS_VARIANT[row.offer.status as OfferStatus]">{{ row.offer.reference }} · {{ t(`admin.recruitment.offerStatuses.${row.offer.status}`) }}</BaseBadge>
            </p>
          </template>
          <template #cell-actions="{ row }">
            <div v-if="isOpen(row as Candidate)" class="flex justify-end gap-2">
              <BaseButton v-if="canUpdate" size="sm" variant="outline" @click="openReject(row as Candidate)">{{ t('admin.recruitment.selection.reject') }}</BaseButton>
              <BaseButton v-if="canCreate" size="sm" @click="makeOffer(row as Candidate)">{{ t('admin.recruitment.selection.makeOffer') }}</BaseButton>
            </div>
          </template>
        </DataTable>
      </div>
    </template>

    <OfferLetterFormModal v-model="offerOpen" :for-applicant="offerFor" @saved="onOfferSaved" />
    <ConfirmReasonModal
      v-model="rejectOpen"
      :title="t('admin.recruitment.selection.rejectTitle', { name: rejectTarget?.name ?? '' })"
      :label="t('admin.recruitment.selection.rejectReason')"
      :confirm-label="t('admin.recruitment.selection.reject')"
      danger
      :submitting="rejecting"
      :error="rejectError"
      @confirm="confirmReject"
    />
    <ApplicantDetailModal v-model="detailOpen" :applicant-id="detailId" @changed="load" />
  </div>
</template>
