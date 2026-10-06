<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import {
  EVALUATION_CRITERIA,
  interviewEvaluationsService,
  RECOMMENDATIONS,
  type EvaluationCriterion,
  type Interview,
  type InterviewEvaluation,
  type Recommendation,
} from '@/services/interviews'
import { ApiRequestError } from '@/types/api'
import { formatDateTime } from '@/utils/date'

/**
 * The signed-in interviewer's evaluation of one interview: 1–5 for each
 * criterion, a recommendation, and notes. Saving again replaces their
 * earlier one (see InterviewEvaluationController::store()).
 */
const props = defineProps<{
  modelValue: boolean
  interview: Interview | null
  /** Their earlier evaluation, to revise. */
  existing?: InterviewEvaluation | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const scores = reactive<Record<EvaluationCriterion, number>>(
  Object.fromEntries(EVALUATION_CRITERIA.map((c) => [c, 0])) as Record<EvaluationCriterion, number>,
)
const recommendation = ref<Recommendation | ''>('')
const strengths = ref('')
const concerns = ref('')

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const average = computed(() => {
  const values = EVALUATION_CRITERIA.map((c) => scores[c]).filter((v) => v > 0)
  return values.length === EVALUATION_CRITERIA.length ? (values.reduce((a, b) => a + b, 0) / values.length).toFixed(2) : null
})

const complete = computed(() => average.value !== null && recommendation.value !== '')

watch(
  () => [props.modelValue, props.existing] as const,
  ([open]) => {
    if (!open) return
    for (const c of EVALUATION_CRITERIA) scores[c] = props.existing?.scores[c] ?? 0
    recommendation.value = props.existing?.recommendation ?? ''
    strengths.value = props.existing?.strengths ?? ''
    concerns.value = props.existing?.concerns ?? ''
    errors.value = {}
    generalError.value = null
  },
  { immediate: true },
)

async function submit() {
  if (!props.interview || !complete.value) return
  submitting.value = true
  errors.value = {}
  generalError.value = null
  try {
    await interviewEvaluationsService.save({
      interview_id: props.interview.id,
      scores: { ...scores },
      recommendation: recommendation.value as Recommendation,
      strengths: strengths.value.trim() || null,
      concerns: concerns.value.trim() || null,
    })
    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) errors.value = error.errors
    else generalError.value = error instanceof ApiRequestError ? error.message : t('admin.recruitment.evaluations.saveFailed')
  } finally {
    submitting.value = false
  }
}

const textareaClass =
  'block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200'
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.recruitment.evaluations.title')" size="lg" @update:model-value="emit('update:modelValue', $event)">
    <form v-if="interview" class="space-y-5" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <div class="rounded-lg bg-neutral-50 px-3 py-2 text-sm">
        <p class="font-semibold text-neutral-900">{{ interview.applicant?.name }}</p>
        <p class="text-neutral-600">
          {{ interview.applicant?.job_title ?? t('admin.recruitment.applicants.generalApplication') }} ·
          {{ t('admin.recruitment.interviews.roundN', { round: interview.round }) }} · {{ formatDateTime(interview.scheduled_at) }}
        </p>
      </div>

      <div class="space-y-3">
        <p class="text-sm text-neutral-500">{{ t('admin.recruitment.evaluations.scaleHint') }}</p>
        <div v-for="criterion in EVALUATION_CRITERIA" :key="criterion" class="flex flex-wrap items-center justify-between gap-2">
          <span class="text-sm font-medium text-neutral-800">{{ t(`admin.recruitment.criteria.${criterion}`) }}</span>
          <div class="flex gap-1" role="radiogroup" :aria-label="t(`admin.recruitment.criteria.${criterion}`)">
            <button
              v-for="value in 5"
              :key="value"
              type="button"
              role="radio"
              :aria-checked="scores[criterion] === value"
              class="h-9 w-9 rounded-lg border text-sm font-semibold transition-colors"
              :class="scores[criterion] === value ? 'border-primary-600 bg-primary-600 text-white' : 'border-neutral-300 text-neutral-600 hover:border-primary-400'"
              @click="scores[criterion] = value"
            >
              {{ value }}
            </button>
          </div>
        </div>
        <p class="text-right text-sm text-neutral-600">
          {{ t('admin.recruitment.evaluations.overall') }}: <span class="font-semibold text-neutral-900">{{ average ?? '—' }}</span> / 5
        </p>
      </div>

      <div>
        <p class="mb-1.5 text-sm font-medium text-neutral-700">{{ t('admin.recruitment.evaluations.recommendation') }} <span class="text-danger-600">*</span></p>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="option in RECOMMENDATIONS"
            :key="option"
            type="button"
            class="rounded-lg border px-4 py-2 text-sm font-medium"
            :class="recommendation === option ? 'border-primary-600 bg-primary-50 text-primary-800' : 'border-neutral-300 text-neutral-600 hover:border-primary-400'"
            @click="recommendation = option"
          >
            {{ t(`admin.recruitment.recommendations.${option}`) }}
          </button>
        </div>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.recruitment.evaluations.strengths') }}</label>
          <textarea v-model="strengths" rows="3" :class="textareaClass" />
        </div>
        <div>
          <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.recruitment.evaluations.concerns') }}</label>
          <textarea v-model="concerns" rows="3" :class="textareaClass" />
        </div>
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" :disabled="!complete" @click="submit">{{ t('admin.recruitment.evaluations.submit') }}</BaseButton>
    </template>
  </BaseModal>
</template>
