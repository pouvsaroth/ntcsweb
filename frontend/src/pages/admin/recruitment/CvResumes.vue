<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import ApplicantDetailModal from '@/components/admin/ApplicantDetailModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import {
  applicantDocumentsService,
  applicantsService,
  DOCUMENT_ACCEPT,
  DOCUMENT_TYPES,
  formatBytes,
  MAX_DOCUMENT_BYTES,
  type Applicant,
  type ApplicantDocument,
  type ApplicantDocumentType,
} from '@/services/applicants'
import { jobPositionsService, type JobPosition } from '@/services/jobPositions'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * HRM > Recruitment > CV/resume — every applicant's files in one list, by
 * type and job. Files are private: they download through the API with the
 * signed-in session, never by a public link.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const canCreate = computed(() => auth.can('recruitment.create'))
const canDelete = computed(() => auth.can('recruitment.delete'))

const jobFilter = ref('')
const { items, meta, loading, error, setPage, setSearch, setFilter, fetch } = usePaginatedResource<ApplicantDocument>((query) =>
  applicantDocumentsService.list(query, jobFilter.value ? { job_position_id: Number(jobFilter.value) } : {}),
)

const jobs = ref<JobPosition[]>([])
const typeFilter = ref('')

const jobFilterOptions = computed(() => [
  { value: '', label: t('admin.recruitment.postings.allJobs') },
  ...jobs.value.map((job) => ({ value: String(job.id), label: `${job.reference} · ${job.title}` })),
])
const typeFilterOptions = computed(() => [
  { value: '', label: t('admin.recruitment.applicants.allFileTypes') },
  ...DOCUMENT_TYPES.map((type) => ({ value: type, label: t(`admin.recruitment.documentTypes.${type}`) })),
])

function onJobFilter(value: string) {
  jobFilter.value = value
  void fetch()
}

function onTypeFilter(value: string) {
  typeFilter.value = value
  setFilter('type', value || undefined)
}

const columns = computed(() => [
  { key: 'file', label: t('admin.recruitment.applicants.file') },
  { key: 'applicant', label: t('admin.recruitment.applicants.name') },
  { key: 'type', label: t('admin.recruitment.applicants.fileType') },
  { key: 'size', label: t('admin.recruitment.applicants.size') },
  { key: 'created_at', label: t('admin.recruitment.applicants.uploadedOn') },
  { key: 'actions', label: t('admin.recruitment.manpower.actions'), align: 'text-right' },
])

const actionError = ref<string | null>(null)

async function download(document: ApplicantDocument) {
  actionError.value = null
  try {
    await applicantDocumentsService.download(document)
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.recruitment.applicants.downloadFailed')
  }
}

async function remove(document: ApplicantDocument) {
  if (!(await confirmDialog.confirm({ message: t('admin.recruitment.applicants.deleteFileConfirm', { name: document.original_name }), danger: true }))) return
  actionError.value = null
  try {
    await applicantDocumentsService.remove(document.id)
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.recruitment.manpower.deleteFailed')
  }
}

// --- Upload ------------------------------------------------------------------

const uploadOpen = ref(false)
const applicants = ref<Applicant[]>([])
const uploadApplicantId = ref('')
const uploadType = ref<ApplicantDocumentType>('cv')
const uploadFile = ref<File | null>(null)
const uploading = ref(false)
const uploadError = ref<string | null>(null)

const applicantOptions = computed(() =>
  applicants.value.map((a) => ({ value: String(a.id), label: a.job_position ? `${a.full_name} — ${a.job_position.title}` : a.full_name })),
)
const typeOptions = computed(() => DOCUMENT_TYPES.map((type) => ({ value: type, label: t(`admin.recruitment.documentTypes.${type}`) })))

async function openUpload() {
  uploadError.value = null
  uploadFile.value = null
  uploadType.value = 'cv'
  uploadOpen.value = true
  if (applicants.value.length === 0) applicants.value = await applicantsService.listAll().catch(() => [])
  uploadApplicantId.value = applicantOptions.value[0]?.value ?? ''
}

function onUploadFile(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0] ?? null
  uploadError.value = file && file.size > MAX_DOCUMENT_BYTES ? t('admin.recruitment.applicants.fileTooLarge') : null
  uploadFile.value = uploadError.value ? null : file
}

async function upload() {
  if (!uploadFile.value || !uploadApplicantId.value) return
  uploading.value = true
  uploadError.value = null
  try {
    await applicantDocumentsService.upload(Number(uploadApplicantId.value), uploadType.value, uploadFile.value)
    uploadOpen.value = false
    await fetch()
  } catch (e) {
    uploadError.value = e instanceof ApiRequestError ? e.message : t('admin.recruitment.applicants.uploadFailed')
  } finally {
    uploading.value = false
  }
}

// --- The applicant behind a file -----------------------------------------------

const detailOpen = ref(false)
const detailId = ref<number | null>(null)

function openApplicant(document: ApplicantDocument) {
  detailId.value = document.applicant_id
  detailOpen.value = true
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
        :placeholder="t('admin.recruitment.applicants.searchFiles')"
        class="block w-full max-w-xs rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseSelect class="w-full max-w-60" :model-value="jobFilter" :options="jobFilterOptions" @update:model-value="onJobFilter" />
      <BaseSelect class="w-40" :model-value="typeFilter" :options="typeFilterOptions" @update:model-value="onTypeFilter" />
      <BaseButton v-if="canCreate" class="ml-auto" @click="openUpload">{{ t('admin.recruitment.applicants.uploadFile') }}</BaseButton>
    </div>

    <BaseAlert v-if="error || actionError" variant="danger" class="mb-4">{{ error || actionError }}</BaseAlert>

    <!-- Cards on a phone (below sm) -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.recruitment.applicants.noFiles') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <button type="button" class="block max-w-full truncate text-left text-sm font-semibold text-primary-700" @click="download(row)">{{ row.original_name }}</button>
          <button type="button" class="block text-left text-xs text-neutral-700 hover:underline" @click="openApplicant(row)">
            {{ row.applicant?.name }}<template v-if="row.applicant?.job_title"> — {{ row.applicant.job_title }}</template>
          </button>
          <p class="text-xs text-neutral-500">{{ t(`admin.recruitment.documentTypes.${row.type}`) }} · {{ formatBytes(row.size) }} · {{ formatDate(row.created_at) }}</p>
          <div v-if="canDelete" class="mt-2 flex justify-end">
            <button type="button" class="text-sm font-medium text-danger-600" @click="remove(row)">{{ t('admin.recruitment.manpower.delete') }}</button>
          </div>
        </div>
      </div>
    </div>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.recruitment.applicants.noFiles')">
        <template #cell-file="{ row }">
          <button type="button" class="max-w-72 truncate text-left font-medium text-primary-700 hover:underline" @click="download(row as ApplicantDocument)">
            {{ row.original_name }}
          </button>
        </template>
        <template #cell-applicant="{ row }">
          <button type="button" class="text-left hover:underline" @click="openApplicant(row as ApplicantDocument)">
            <p>{{ row.applicant?.name }}</p>
            <p v-if="row.applicant?.job_title" class="text-xs text-neutral-500">{{ row.applicant.job_title }}</p>
          </button>
        </template>
        <template #cell-type="{ row }">{{ t(`admin.recruitment.documentTypes.${row.type}`) }}</template>
        <template #cell-size="{ row }">{{ formatBytes(row.size) }}</template>
        <template #cell-created_at="{ row }">
          <p>{{ formatDate(row.created_at) }}</p>
          <p class="text-xs text-neutral-500">{{ row.uploaded_by ?? t('admin.recruitment.applicants.byApplicant') }}</p>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-3">
            <button type="button" class="text-sm font-medium text-primary-700 hover:underline" @click="download(row as ApplicantDocument)">{{ t('admin.recruitment.applicants.download') }}</button>
            <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row as ApplicantDocument)">
              {{ t('admin.recruitment.manpower.delete') }}
            </button>
          </div>
        </template>
      </DataTable>
    </div>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <BaseModal v-model="uploadOpen" :title="t('admin.recruitment.applicants.uploadFile')">
      <div class="space-y-4">
        <BaseAlert v-if="uploadError" variant="danger">{{ uploadError }}</BaseAlert>
        <p v-if="applicantOptions.length === 0" class="text-sm text-neutral-500">{{ t('admin.recruitment.applicants.empty') }}</p>
        <BaseSelect v-else v-model="uploadApplicantId" :options="applicantOptions" :label="t('admin.recruitment.applicants.name')" />
        <BaseSelect v-model="uploadType" :options="typeOptions" :label="t('admin.recruitment.applicants.fileType')" />
        <div>
          <input
            type="file"
            :accept="DOCUMENT_ACCEPT"
            class="block w-full text-sm text-neutral-600 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-800 hover:file:bg-primary-100"
            @change="onUploadFile"
          />
          <p class="mt-1 text-xs text-neutral-500">{{ t('admin.recruitment.applicants.fileHint') }}</p>
        </div>
      </div>
      <template #footer>
        <BaseButton variant="outline" @click="uploadOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="uploading" :disabled="!uploadFile || !uploadApplicantId" @click="upload">{{ t('admin.recruitment.applicants.uploadFile') }}</BaseButton>
      </template>
    </BaseModal>

    <ApplicantDetailModal v-model="detailOpen" :applicant-id="detailId" @changed="fetch" />
  </div>
</template>
