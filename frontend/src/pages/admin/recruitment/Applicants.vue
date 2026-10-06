<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import ApplicantDetailModal from '@/components/admin/ApplicantDetailModal.vue'
import ApplicantFormModal from '@/components/admin/ApplicantFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { APPLICANT_SOURCES, APPLICANT_STAGES, applicantsService, STAGE_VARIANT, type Applicant, type ApplicantStage } from '@/services/applicants'
import { jobPositionsService, type JobPosition } from '@/services/jobPositions'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * HRM > Recruitment > Applicant management — everyone who applied (from the
 * website's Careers page, or added by HR), by job, stage and source. Tap
 * one for their details and files. Cards on a phone, a table from `sm` up.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const canCreate = computed(() => auth.can('recruitment.create'))
const canDelete = computed(() => auth.can('recruitment.delete'))

const { items, meta, loading, error, setPage, setSearch, setFilter, fetch } = usePaginatedResource<Applicant>((query) => applicantsService.list(query))

const jobs = ref<JobPosition[]>([])
const jobFilter = ref('')
const stageFilter = ref('')
const sourceFilter = ref('')

const jobFilterOptions = computed(() => [
  { value: '', label: t('admin.recruitment.postings.allJobs') },
  ...jobs.value.map((job) => ({ value: String(job.id), label: `${job.reference} · ${job.title}` })),
])
const stageFilterOptions = computed(() => [
  { value: '', label: t('admin.recruitment.applicants.allStages') },
  ...APPLICANT_STAGES.map((stage) => ({ value: stage, label: t(`admin.recruitment.stages.${stage}`) })),
])
const sourceFilterOptions = computed(() => [
  { value: '', label: t('admin.recruitment.applicants.allSources') },
  ...APPLICANT_SOURCES.map((source) => ({ value: source, label: t(`admin.recruitment.sources.${source}`) })),
])

function filterBy(key: 'job_position_id' | 'stage' | 'source', value: string) {
  if (key === 'job_position_id') jobFilter.value = value
  if (key === 'stage') stageFilter.value = value
  if (key === 'source') sourceFilter.value = value
  setFilter(key, value || undefined)
}

const columns = computed(() => [
  { key: 'name', label: t('admin.recruitment.applicants.name') },
  { key: 'job', label: t('admin.recruitment.applicants.appliedFor') },
  { key: 'source', label: t('admin.recruitment.applicants.source') },
  { key: 'created_at', label: t('admin.recruitment.applicants.appliedOn') },
  { key: 'documents', label: t('admin.recruitment.tabs.cvs') },
  { key: 'stage', label: t('admin.recruitment.applicants.stage') },
  { key: 'actions', label: t('admin.recruitment.manpower.actions'), align: 'text-right' },
])

const detailOpen = ref(false)
const detailId = ref<number | null>(null)
const formOpen = ref(false)
const editing = ref<Applicant | null>(null)
const actionError = ref<string | null>(null)

function openDetail(applicant: Applicant) {
  detailId.value = applicant.id
  detailOpen.value = true
}

function openCreate() {
  editing.value = null
  formOpen.value = true
}

function openEdit(applicant: Applicant) {
  editing.value = applicant
  detailOpen.value = false
  formOpen.value = true
}

function onSaved(applicant: Applicant) {
  void fetch()
  detailId.value = applicant.id
  detailOpen.value = true
}

async function remove(applicant: Applicant) {
  if (!(await confirmDialog.confirm({ message: t('admin.recruitment.applicants.deleteConfirm', { name: applicant.full_name }), danger: true }))) return
  actionError.value = null
  try {
    await applicantsService.remove(applicant.id)
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.recruitment.manpower.deleteFailed')
  }
}

onMounted(async () => {
  void fetch()
  jobs.value = await jobPositionsService.listAll().catch(() => [])
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <input
        type="search"
        :placeholder="t('admin.recruitment.applicants.searchPlaceholder')"
        class="block w-full max-w-xs rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseSelect class="w-full max-w-60" :model-value="jobFilter" :options="jobFilterOptions" @update:model-value="(v: string) => filterBy('job_position_id', v)" />
      <BaseSelect class="w-40" :model-value="stageFilter" :options="stageFilterOptions" @update:model-value="(v: string) => filterBy('stage', v)" />
      <BaseSelect class="w-40" :model-value="sourceFilter" :options="sourceFilterOptions" @update:model-value="(v: string) => filterBy('source', v)" />
      <BaseButton v-if="canCreate" class="ml-auto" @click="openCreate">{{ t('admin.recruitment.applicants.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error || actionError" variant="danger" class="mb-4">{{ error || actionError }}</BaseAlert>

    <!-- Cards on a phone (below sm) -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.recruitment.applicants.empty') }}
      </p>
      <div v-else class="space-y-2">
        <button
          v-for="row in items"
          :key="row.id"
          type="button"
          class="block w-full rounded-[--radius-card] border border-neutral-200 bg-white p-3 text-left shadow-[--shadow-card]"
          @click="openDetail(row)"
        >
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate text-sm font-semibold text-neutral-800">{{ row.full_name }}</p>
              <p class="text-xs text-neutral-500">{{ row.phone }}</p>
            </div>
            <BaseBadge :variant="STAGE_VARIANT[row.stage]" class="shrink-0">{{ t(`admin.recruitment.stages.${row.stage}`) }}</BaseBadge>
          </div>
          <p class="mt-2 truncate text-xs text-neutral-600">{{ row.job_position?.title ?? t('admin.recruitment.applicants.generalApplication') }}</p>
          <p class="text-xs text-neutral-500">
            {{ t(`admin.recruitment.sources.${row.source}`) }} · {{ formatDate(row.created_at) }} · {{ t('admin.recruitment.applicants.filesCount', { count: row.documents_count ?? 0 }) }}
          </p>
        </button>
      </div>
    </div>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.recruitment.applicants.empty')">
        <template #cell-name="{ row }">
          <button type="button" class="text-left" @click="openDetail(row as Applicant)">
            <p class="font-medium text-primary-700 hover:underline">{{ row.full_name }}</p>
            <p class="text-xs text-neutral-500">{{ row.phone }}<template v-if="row.email"> · {{ row.email }}</template></p>
          </button>
        </template>
        <template #cell-job="{ row }">
          <p>{{ row.job_position?.title ?? t('admin.recruitment.applicants.generalApplication') }}</p>
          <p v-if="row.job_position" class="text-xs text-neutral-500">{{ row.job_position.reference }}</p>
        </template>
        <template #cell-source="{ row }">{{ t(`admin.recruitment.sources.${row.source}`) }}</template>
        <template #cell-created_at="{ row }">{{ formatDate(row.created_at) }}</template>
        <template #cell-documents="{ row }">{{ row.documents_count ?? 0 }}</template>
        <template #cell-stage="{ row }">
          <BaseBadge :variant="STAGE_VARIANT[row.stage as ApplicantStage]">{{ t(`admin.recruitment.stages.${row.stage}`) }}</BaseBadge>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-3">
            <button type="button" class="text-sm font-medium text-primary-700 hover:underline" @click="openDetail(row as Applicant)">{{ t('admin.recruitment.applicants.view') }}</button>
            <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row as Applicant)">
              {{ t('admin.recruitment.manpower.delete') }}
            </button>
          </div>
        </template>
      </DataTable>
    </div>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <ApplicantDetailModal v-model="detailOpen" :applicant-id="detailId" @changed="fetch" @edit="openEdit" />
    <ApplicantFormModal v-model="formOpen" :applicant="editing" :jobs="jobs" @saved="onSaved" />
  </div>
</template>
