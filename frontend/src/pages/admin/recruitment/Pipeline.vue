<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import ApplicantDetailModal from '@/components/admin/ApplicantDetailModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { APPLICANT_STAGES, applicantsService, type Applicant, type ApplicantStage } from '@/services/applicants'
import { jobPositionsService, type JobPosition } from '@/services/jobPositions'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * HRM > Recruitment > Recruitment pipeline: every applicant as a card in
 * the column of their stage. Drag a card to move it (on a computer), or pick
 * the stage on the card (on a phone, where dragging doesn't work). Hired is
 * reached only through Hire on an accepted offer, so cards can't be dropped
 * there (the server refuses it too).
 */
const { t } = useI18n()
const auth = useAuthStore()

const canUpdate = computed(() => auth.can('recruitment.update'))

const jobs = ref<JobPosition[]>([])
const jobFilter = ref('')
const applicants = ref<Applicant[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const notice = ref<string | null>(null)

const jobFilterOptions = computed(() => [
  { value: '', label: t('admin.recruitment.postings.allJobs') },
  ...jobs.value.map((job) => ({ value: String(job.id), label: `${job.reference} · ${job.title}` })),
])

/** Columns in hiring order, then the two ways out. */
const columns = computed(() =>
  APPLICANT_STAGES.map((stage) => ({ stage, cards: applicants.value.filter((a) => a.stage === stage) })),
)

const columnTone: Record<ApplicantStage, string> = {
  new: 'border-t-neutral-400',
  screening: 'border-t-primary-400',
  shortlisted: 'border-t-primary-600',
  interview: 'border-t-amber-400',
  offer: 'border-t-amber-600',
  hired: 'border-t-success-600',
  rejected: 'border-t-danger-600',
  withdrawn: 'border-t-neutral-300',
}

async function load() {
  loading.value = true
  error.value = null
  try {
    const page = await applicantsService.list({ page: 1, per_page: 500, filter: jobFilter.value ? { job_position_id: jobFilter.value } : {} })
    applicants.value = page.data
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.recruitment.pipeline.loadFailed')
  } finally {
    loading.value = false
  }
}

function onJob(value: string) {
  jobFilter.value = value
  void load()
}

/** Moves the card at once, and puts it back if the server says no. */
async function move(applicant: Applicant, stage: ApplicantStage) {
  if (stage === applicant.stage) return
  notice.value = null
  if (stage === 'hired') {
    notice.value = t('admin.recruitment.pipeline.hireThroughOffer')
    return
  }

  const previous = applicant.stage
  applicant.stage = stage
  try {
    await applicantsService.update(applicant.id, { stage })
  } catch (e) {
    applicant.stage = previous
    notice.value = e instanceof ApiRequestError ? e.message : t('admin.recruitment.applicants.saveFailed')
  }
}

// --- Dragging (mouse) -----------------------------------------------------------

const dragging = ref<Applicant | null>(null)
const over = ref<ApplicantStage | null>(null)

function onDragStart(event: DragEvent, applicant: Applicant) {
  dragging.value = applicant
  event.dataTransfer?.setData('text/plain', String(applicant.id))
  if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move'
}

function onDragEnd() {
  dragging.value = null
  over.value = null
}

function onDrop(stage: ApplicantStage) {
  const applicant = dragging.value
  onDragEnd()
  if (applicant) void move(applicant, stage)
}

// --- Picking the stage (phone) ----------------------------------------------------

const stageOptions = computed(() =>
  APPLICANT_STAGES.filter((stage) => stage !== 'hired').map((stage) => ({ value: stage, label: t(`admin.recruitment.stages.${stage}`) })),
)

// --- Details ---------------------------------------------------------------------

const detailOpen = ref(false)
const detailId = ref<number | null>(null)

function openDetail(applicant: Applicant) {
  if (dragging.value) return
  detailId.value = applicant.id
  detailOpen.value = true
}

onMounted(async () => {
  void load()
  jobs.value = await jobPositionsService.listAll().catch(() => [])
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <BaseSelect class="w-full max-w-md" :model-value="jobFilter" :options="jobFilterOptions" @update:model-value="onJob" />
      <p class="text-sm text-neutral-500">
        {{ t('admin.recruitment.pipeline.total', { count: applicants.length }) }}
        <span class="hidden lg:inline"> · {{ t('admin.recruitment.pipeline.dragHint') }}</span>
      </p>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="notice" variant="warning" class="mb-4">{{ notice }}</BaseAlert>

    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>

    <!-- The board: columns side by side, scrolling sideways when they don't fit. -->
    <div v-else class="-mx-4 overflow-x-auto px-4 pb-4 sm:mx-0 sm:px-0">
      <div class="flex min-w-max gap-3">
        <section
          v-for="column in columns"
          :key="column.stage"
          class="flex w-64 shrink-0 flex-col rounded-[--radius-card] border border-t-4 border-neutral-200 bg-neutral-50 transition-colors"
          :class="[columnTone[column.stage], over === column.stage && dragging && column.stage !== 'hired' ? 'bg-primary-50 ring-2 ring-primary-300' : '']"
          @dragover.prevent="over = column.stage"
          @dragleave="over = over === column.stage ? null : over"
          @drop.prevent="onDrop(column.stage)"
        >
          <header class="flex items-center justify-between px-3 py-2">
            <h2 class="text-sm font-semibold text-neutral-800">{{ t(`admin.recruitment.stages.${column.stage}`) }}</h2>
            <span class="rounded-full bg-white px-2 text-xs font-semibold text-neutral-600 ring-1 ring-neutral-200">{{ column.cards.length }}</span>
          </header>

          <div class="flex max-h-[65vh] min-h-24 flex-1 flex-col gap-2 overflow-y-auto px-2 pb-2">
            <article
              v-for="applicant in column.cards"
              :key="applicant.id"
              class="rounded-lg border border-neutral-200 bg-white p-2.5 shadow-sm"
              :class="[canUpdate && applicant.stage !== 'hired' ? 'cursor-grab active:cursor-grabbing' : '', dragging?.id === applicant.id ? 'opacity-50' : '']"
              :draggable="canUpdate && applicant.stage !== 'hired'"
              @dragstart="onDragStart($event, applicant)"
              @dragend="onDragEnd"
            >
              <button type="button" class="block w-full text-left" @click="openDetail(applicant)">
                <p class="truncate text-sm font-semibold text-neutral-800">{{ applicant.full_name }}</p>
                <p class="truncate text-xs text-neutral-500">{{ applicant.job_position?.title ?? t('admin.recruitment.applicants.generalApplication') }}</p>
                <p class="mt-1 text-[11px] text-neutral-400">
                  {{ t(`admin.recruitment.sources.${applicant.source}`) }} · {{ formatDate(applicant.created_at) }}
                </p>
              </button>
              <!-- No dragging on a touch screen: pick the stage instead. -->
              <BaseSelect
                v-if="canUpdate && applicant.stage !== 'hired'"
                class="mt-2 lg:hidden"
                :model-value="applicant.stage"
                :options="stageOptions"
                @update:model-value="(value: string) => move(applicant, value as ApplicantStage)"
              />
            </article>
            <p v-if="column.cards.length === 0" class="py-4 text-center text-xs text-neutral-400">{{ t('admin.recruitment.pipeline.emptyColumn') }}</p>
          </div>
        </section>
      </div>
    </div>

    <ApplicantDetailModal v-model="detailOpen" :applicant-id="detailId" @changed="load" />
  </div>
</template>
