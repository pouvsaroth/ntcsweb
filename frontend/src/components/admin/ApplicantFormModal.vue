<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import {
  APPLICANT_SOURCES,
  APPLICANT_STAGES,
  applicantsService,
  DOCUMENT_ACCEPT,
  MAX_DOCUMENT_BYTES,
  type Applicant,
  type ApplicantSource,
  type ApplicantStage,
} from '@/services/applicants'
import type { JobPosition } from '@/services/jobPositions'
import { ApiRequestError } from '@/types/api'

/** HR adds an applicant by hand (with their CV), or edits one. */
const props = defineProps<{
  modelValue: boolean
  applicant?: Applicant | null
  jobs: JobPosition[]
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [applicant: Applicant] }>()

const { t } = useI18n()

const isEditing = computed(() => props.applicant != null)

const form = reactive({
  job_position_id: '',
  first_name: '',
  last_name: '',
  gender: '',
  date_of_birth: '',
  phone: '',
  email: '',
  address: '',
  source: 'walk_in' as ApplicantSource,
  expected_salary: '',
  available_from: '',
  cover_letter: '',
  stage: 'new' as ApplicantStage,
  notes: '',
})
const cv = ref<File | null>(null)

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const jobOptions = computed(() => [
  { value: '', label: t('admin.recruitment.applicants.generalApplication') },
  ...props.jobs.map((job) => ({ value: String(job.id), label: `${job.reference} · ${job.title}` })),
])
const genderOptions = computed(() => [
  { value: '', label: '—' },
  { value: 'male', label: t('common.genderMale') },
  { value: 'female', label: t('common.genderFemale') },
])
const sourceOptions = computed(() => APPLICANT_SOURCES.map((source) => ({ value: source, label: t(`admin.recruitment.sources.${source}`) })))
// Hired only comes from Hire on an accepted offer — offered here just to show it for someone already hired.
const stageOptions = computed(() =>
  APPLICANT_STAGES.filter((stage) => stage !== 'hired' || props.applicant?.stage === 'hired').map((stage) => ({ value: stage, label: t(`admin.recruitment.stages.${stage}`) })),
)

watch(
  () => [props.modelValue, props.applicant] as const,
  ([open]) => {
    if (!open) return

    const a = props.applicant
    form.job_position_id = a?.job_position_id ? String(a.job_position_id) : ''
    form.first_name = a?.first_name ?? ''
    form.last_name = a?.last_name ?? ''
    form.gender = a?.gender ?? ''
    form.date_of_birth = a?.date_of_birth ?? ''
    form.phone = a?.phone ?? ''
    form.email = a?.email ?? ''
    form.address = a?.address ?? ''
    form.source = a?.source ?? 'walk_in'
    form.expected_salary = a?.expected_salary != null ? String(a.expected_salary) : ''
    form.available_from = a?.available_from ?? ''
    form.cover_letter = a?.cover_letter ?? ''
    form.stage = a?.stage ?? 'new'
    form.notes = a?.notes ?? ''
    cv.value = null
    errors.value = {}
    generalError.value = null
  },
  { immediate: true },
)

function onCvChange(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0] ?? null
  if (file && file.size > MAX_DOCUMENT_BYTES) {
    errors.value = { ...errors.value, cv: [t('admin.recruitment.applicants.fileTooLarge')] }
    ;(event.target as HTMLInputElement).value = ''
    cv.value = null
    return
  }
  cv.value = file
}

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  const input = { ...form, job_position_id: form.job_position_id ? Number(form.job_position_id) : null, cv: cv.value }

  try {
    const saved = isEditing.value ? await applicantsService.update(props.applicant!.id, input) : await applicantsService.create(input)
    emit('saved', saved)
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.recruitment.applicants.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}

const textareaClass =
  'block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200'
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.recruitment.applicants.edit') : t('admin.recruitment.applicants.add')"
    size="lg"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseSelect v-model="form.job_position_id" :options="jobOptions" :label="t('admin.recruitment.applicants.appliedFor')" :error="errors.job_position_id?.[0]" />

      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.first_name" required :label="t('admin.staff.firstName')" :error="errors.first_name?.[0]" />
        <BaseInput v-model="form.last_name" required :label="t('admin.staff.lastName')" :error="errors.last_name?.[0]" />
        <BaseSelect v-model="form.gender" :options="genderOptions" :label="t('admin.staff.gender')" />
        <BaseInput v-model="form.date_of_birth" type="date" :label="t('admin.staff.dateOfBirth')" :error="errors.date_of_birth?.[0]" />
        <BaseInput v-model="form.phone" required :label="t('admin.staff.phone')" :error="errors.phone?.[0]" />
        <BaseInput v-model="form.email" type="email" :label="t('admin.staff.email')" :error="errors.email?.[0]" />
        <BaseInput v-model="form.address" class="sm:col-span-2" :label="t('admin.recruitment.applicants.address')" :error="errors.address?.[0]" />
        <BaseSelect v-model="form.source" :options="sourceOptions" :label="t('admin.recruitment.applicants.source')" />
        <BaseSelect v-model="form.stage" :options="stageOptions" :label="t('admin.recruitment.applicants.stage')" />
        <BaseInput v-model="form.expected_salary" type="number" min="0" :label="t('admin.recruitment.applicants.expectedSalary')" :error="errors.expected_salary?.[0]" />
        <BaseInput v-model="form.available_from" type="date" :label="t('admin.recruitment.applicants.availableFrom')" :error="errors.available_from?.[0]" />
      </div>

      <div v-if="!isEditing">
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.recruitment.applicants.cv') }}</label>
        <input
          type="file"
          :accept="DOCUMENT_ACCEPT"
          class="block w-full text-sm text-neutral-600 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-800 hover:file:bg-primary-100"
          @change="onCvChange"
        />
        <p class="mt-1 text-xs text-neutral-500">{{ t('admin.recruitment.applicants.fileHint') }}</p>
        <p v-if="errors.cv?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.cv[0] }}</p>
      </div>

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.recruitment.applicants.coverLetter') }}</label>
        <textarea v-model="form.cover_letter" rows="3" :class="textareaClass" />
      </div>
      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.recruitment.applicants.notes') }}</label>
        <textarea v-model="form.notes" rows="2" :class="textareaClass" :placeholder="t('admin.recruitment.applicants.notesHint')" />
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
