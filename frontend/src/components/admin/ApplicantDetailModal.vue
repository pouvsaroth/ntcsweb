<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import {
  APPLICANT_STAGES,
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
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'
import { genderLabel } from '@/utils/gender'

/**
 * One applicant: their details, a quick stage change, and their files
 * (download, add, remove). Emits `changed` whenever something was saved so
 * the list behind it can refresh.
 */
const props = defineProps<{ modelValue: boolean; applicantId: number | null }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; changed: []; edit: [applicant: Applicant] }>()

const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const canUpdate = computed(() => auth.can('recruitment.update'))
const canCreate = computed(() => auth.can('recruitment.create'))
const canDelete = computed(() => auth.can('recruitment.delete'))

const applicant = ref<Applicant | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const busy = ref(false)

async function load() {
  if (props.applicantId === null) return
  loading.value = true
  error.value = null
  try {
    applicant.value = await applicantsService.get(props.applicantId)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.recruitment.applicants.loadFailed')
  } finally {
    loading.value = false
  }
}

watch(
  () => [props.modelValue, props.applicantId] as const,
  ([open]) => {
    if (open) void load()
    else applicant.value = null
  },
  { immediate: true },
)

// Hired only comes from Hire on an accepted offer.
const stageOptions = computed(() =>
  APPLICANT_STAGES.filter((stage) => stage !== 'hired' || applicant.value?.stage === 'hired').map((stage) => ({ value: stage, label: t(`admin.recruitment.stages.${stage}`) })),
)
const typeOptions = computed(() => DOCUMENT_TYPES.map((type) => ({ value: type, label: t(`admin.recruitment.documentTypes.${type}`) })))

async function changeStage(stage: string) {
  if (!applicant.value || stage === applicant.value.stage) return
  busy.value = true
  error.value = null
  try {
    applicant.value = await applicantsService.update(applicant.value.id, { stage: stage as Applicant['stage'] })
    emit('changed')
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.recruitment.applicants.saveFailed')
  } finally {
    busy.value = false
  }
}

// --- Files -------------------------------------------------------------------

const uploadType = ref<ApplicantDocumentType>('cv')
const fileInput = ref<HTMLInputElement | null>(null)

async function onFile(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file || !applicant.value) return
  if (file.size > MAX_DOCUMENT_BYTES) {
    error.value = t('admin.recruitment.applicants.fileTooLarge')
    return
  }

  busy.value = true
  error.value = null
  try {
    await applicantDocumentsService.upload(applicant.value.id, uploadType.value, file)
    await load()
    emit('changed')
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.recruitment.applicants.uploadFailed')
  } finally {
    busy.value = false
  }
}

async function download(document: ApplicantDocument) {
  error.value = null
  try {
    await applicantDocumentsService.download(document)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.recruitment.applicants.downloadFailed')
  }
}

async function removeDocument(document: ApplicantDocument) {
  if (!(await confirmDialog.confirm({ message: t('admin.recruitment.applicants.deleteFileConfirm', { name: document.original_name }), danger: true }))) return
  busy.value = true
  try {
    await applicantDocumentsService.remove(document.id)
    await load()
    emit('changed')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="applicant?.full_name ?? ''" size="lg" @update:model-value="emit('update:modelValue', $event)">
    <BaseSpinner v-if="loading && !applicant" class="mx-auto my-8" />

    <div v-else-if="applicant" class="space-y-6">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>

      <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
          <p class="text-sm text-neutral-500">{{ t('admin.recruitment.applicants.appliedFor') }}</p>
          <p class="font-medium text-neutral-900">
            {{ applicant.job_position ? `${applicant.job_position.reference} · ${applicant.job_position.title}` : t('admin.recruitment.applicants.generalApplication') }}
          </p>
        </div>
        <BaseSelect
          class="w-48"
          :model-value="applicant.stage"
          :options="stageOptions"
          :disabled="!canUpdate || busy"
          :label="t('admin.recruitment.applicants.stage')"
          @update:model-value="changeStage"
        />
      </div>

      <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
        <div><dt class="text-neutral-500">{{ t('admin.staff.phone') }}</dt><dd class="font-medium text-neutral-900">{{ applicant.phone }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.staff.email') }}</dt><dd class="font-medium text-neutral-900">{{ applicant.email ?? '—' }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.staff.gender') }}</dt><dd class="font-medium text-neutral-900">{{ genderLabel(applicant.gender) }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.staff.dateOfBirth') }}</dt><dd class="font-medium text-neutral-900">{{ formatDate(applicant.date_of_birth) }}</dd></div>
        <div class="sm:col-span-2"><dt class="text-neutral-500">{{ t('admin.recruitment.applicants.address') }}</dt><dd class="font-medium text-neutral-900">{{ applicant.address ?? '—' }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.recruitment.applicants.source') }}</dt><dd class="font-medium text-neutral-900">{{ t(`admin.recruitment.sources.${applicant.source}`) }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.recruitment.applicants.appliedOn') }}</dt><dd class="font-medium text-neutral-900">{{ formatDate(applicant.created_at) }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.recruitment.applicants.expectedSalary') }}</dt><dd class="font-medium text-neutral-900">{{ applicant.expected_salary ?? '—' }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.recruitment.applicants.availableFrom') }}</dt><dd class="font-medium text-neutral-900">{{ formatDate(applicant.available_from) }}</dd></div>
      </dl>

      <section v-if="applicant.cover_letter">
        <h3 class="mb-1 text-sm font-semibold text-neutral-800">{{ t('admin.recruitment.applicants.coverLetter') }}</h3>
        <p class="whitespace-pre-line text-sm text-neutral-700">{{ applicant.cover_letter }}</p>
      </section>
      <section v-if="applicant.notes">
        <h3 class="mb-1 text-sm font-semibold text-neutral-800">{{ t('admin.recruitment.applicants.notes') }}</h3>
        <p class="whitespace-pre-line text-sm text-neutral-700">{{ applicant.notes }}</p>
      </section>

      <section>
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
          <h3 class="text-sm font-semibold text-neutral-800">{{ t('admin.recruitment.tabs.cvs') }}</h3>
          <div v-if="canCreate" class="flex items-center gap-2">
            <BaseSelect v-model="uploadType" class="w-40" :options="typeOptions" />
            <BaseButton size="sm" variant="outline" :loading="busy" @click="fileInput?.click()">{{ t('admin.recruitment.applicants.addFile') }}</BaseButton>
            <input ref="fileInput" type="file" class="hidden" :accept="DOCUMENT_ACCEPT" @change="onFile" />
          </div>
        </div>
        <p v-if="!applicant.documents?.length" class="rounded-lg border border-dashed border-neutral-300 p-4 text-center text-sm text-neutral-500">
          {{ t('admin.recruitment.applicants.noFiles') }}
        </p>
        <ul v-else class="divide-y divide-neutral-100 rounded-lg border border-neutral-200">
          <li v-for="document in applicant.documents" :key="document.id" class="flex items-center gap-3 px-3 py-2">
            <div class="min-w-0 flex-1">
              <button type="button" class="block max-w-full truncate text-left text-sm font-medium text-primary-700 hover:underline" @click="download(document)">
                {{ document.original_name }}
              </button>
              <p class="text-xs text-neutral-500">
                {{ t(`admin.recruitment.documentTypes.${document.type}`) }} · {{ formatBytes(document.size) }} · {{ formatDate(document.created_at) }}
              </p>
            </div>
            <button v-if="canDelete" type="button" class="shrink-0 text-xs font-medium text-danger-600" @click="removeDocument(document)">{{ t('admin.recruitment.manpower.delete') }}</button>
          </li>
        </ul>
      </section>
    </div>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton v-if="applicant && canUpdate" @click="emit('edit', applicant)">{{ t('common.edit') }}</BaseButton>
    </template>
  </BaseModal>
</template>
