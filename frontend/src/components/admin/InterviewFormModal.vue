<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseMultiSelect from '@/components/ui/BaseMultiSelect.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import type { Applicant } from '@/services/applicants'
import {
  fromDateTimeLocal,
  INTERVIEW_MODES,
  INTERVIEW_STATUSES,
  interviewsService,
  toDateTimeLocal,
  type Interview,
  type Interviewer,
  type InterviewMode,
  type InterviewStatus,
} from '@/services/interviews'
import { ApiRequestError } from '@/types/api'

/** Schedule an interview, or change one (time, place, interviewers, status). */
const props = defineProps<{
  modelValue: boolean
  interview?: Interview | null
  applicants: Applicant[]
  interviewers: Interviewer[]
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.interview != null)

const form = reactive({
  applicant_id: '',
  round: '1',
  scheduled_at: '',
  duration_minutes: '60',
  mode: 'in_person' as InterviewMode,
  location: '',
  status: 'scheduled' as InterviewStatus,
  notes: '',
  interviewer_ids: [] as string[],
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

/** Applicants still in the running — someone hired, rejected or withdrawn isn't interviewed again. */
const applicantOptions = computed(() =>
  props.applicants
    .filter((a) => !['hired', 'rejected', 'withdrawn'].includes(a.stage) || String(a.id) === form.applicant_id)
    .map((a) => ({ value: String(a.id), label: a.job_position ? `${a.full_name} — ${a.job_position.title}` : a.full_name })),
)
const interviewerOptions = computed(() => props.interviewers.map((u) => ({ value: String(u.id), label: u.name })))
const modeOptions = computed(() => INTERVIEW_MODES.map((mode) => ({ value: mode, label: t(`admin.recruitment.interviewModes.${mode}`) })))
const statusOptions = computed(() => INTERVIEW_STATUSES.map((status) => ({ value: status, label: t(`admin.recruitment.interviewStatuses.${status}`) })))

/** Tomorrow 09:00 — a sensible first suggestion for a new interview. */
function defaultTime(): string {
  const d = new Date()
  d.setDate(d.getDate() + 1)
  d.setHours(9, 0, 0, 0)
  return toDateTimeLocal(d.toISOString())
}

watch(
  () => [props.modelValue, props.interview] as const,
  ([open]) => {
    if (!open) return

    const i = props.interview
    form.applicant_id = i ? String(i.applicant_id) : ''
    form.round = String(i?.round ?? 1)
    form.scheduled_at = i ? toDateTimeLocal(i.scheduled_at) : defaultTime()
    form.duration_minutes = String(i?.duration_minutes ?? 60)
    form.mode = i?.mode ?? 'in_person'
    form.location = i?.location ?? ''
    form.status = i?.status ?? 'scheduled'
    form.notes = i?.notes ?? ''
    form.interviewer_ids = (i?.interviewers ?? []).map((u) => String(u.id))
    errors.value = {}
    generalError.value = null
  },
  { immediate: true },
)

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  const input = {
    applicant_id: Number(form.applicant_id),
    round: Number(form.round),
    scheduled_at: form.scheduled_at ? fromDateTimeLocal(form.scheduled_at) : '',
    duration_minutes: Number(form.duration_minutes),
    mode: form.mode,
    location: form.location.trim() || null,
    status: form.status,
    notes: form.notes.trim() || null,
    interviewer_ids: form.interviewer_ids.map(Number),
  }

  try {
    if (isEditing.value) await interviewsService.update(props.interview!.id, input)
    else await interviewsService.create(input)

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.recruitment.interviews.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.recruitment.interviews.edit') : t('admin.recruitment.interviews.add')"
    size="lg"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>
      <p v-if="!isEditing" class="text-sm text-neutral-500">{{ t('admin.recruitment.interviews.scheduleHint') }}</p>

      <BaseSelect
        v-model="form.applicant_id"
        required
        :disabled="isEditing"
        :options="applicantOptions"
        :placeholder="t('admin.recruitment.interviews.selectApplicant')"
        :label="t('admin.recruitment.applicants.name')"
        :error="errors.applicant_id?.[0]"
      />

      <div class="grid gap-4 sm:grid-cols-3">
        <BaseInput v-model="form.scheduled_at" type="datetime-local" required class="sm:col-span-2" :label="t('admin.recruitment.interviews.when')" :error="errors.scheduled_at?.[0]" />
        <BaseInput v-model="form.duration_minutes" type="number" min="5" :label="t('admin.recruitment.interviews.minutes')" :error="errors.duration_minutes?.[0]" />
        <BaseSelect v-model="form.mode" :options="modeOptions" :label="t('admin.recruitment.interviews.mode')" />
        <BaseInput v-model="form.round" type="number" min="1" :label="t('admin.recruitment.interviews.round')" :error="errors.round?.[0]" />
        <BaseSelect v-if="isEditing" v-model="form.status" :options="statusOptions" :label="t('admin.recruitment.interviews.status')" />
      </div>

      <BaseInput
        v-model="form.location"
        :label="form.mode === 'online' ? t('admin.recruitment.interviews.meetingLink') : t('admin.recruitment.interviews.location')"
        :placeholder="form.mode === 'online' ? 'https://' : ''"
        :error="errors.location?.[0]"
      />

      <BaseMultiSelect
        v-model="form.interviewer_ids"
        :options="interviewerOptions"
        :label="t('admin.recruitment.interviews.interviewers')"
        :placeholder="t('admin.recruitment.interviews.selectInterviewers')"
        :hint="t('admin.recruitment.interviews.interviewersHint')"
        :error="errors.interviewer_ids?.[0] ?? errors['interviewer_ids.0']?.[0]"
      />

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.recruitment.applicants.notes') }}</label>
        <textarea
          v-model="form.notes"
          rows="2"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
