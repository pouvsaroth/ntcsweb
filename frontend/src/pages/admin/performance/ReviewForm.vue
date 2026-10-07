<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import StarRating from '@/pages/admin/performance/StarRating.vue'
import { scoreLabel, type PerformanceReviewDetail, type ReviewSave } from '@/services/performance'
import { formatDate } from '@/utils/date'

/**
 * One performance review, filled in by one side:
 *
 * - 'self': the staff member rates their KPIs (and enters the actual
 *   result), goals and form questions, and comments.
 * - 'manager': the manager does the same on their side, seeing the staff
 *   member's own ratings next to each line.
 * - 'hr': read-only, both sides.
 *
 * `editable` is false once that side has sent it. `draft()` returns what
 * to save — the parent saves / submits it.
 */
const props = defineProps<{ review: PerformanceReviewDetail; side: 'self' | 'manager' | 'hr'; editable: boolean }>()

const { t } = useI18n()

type Row = { rating: number | null; text: string; actual: string; progress: number; comment: string }
const state = reactive({
  kpis: {} as Record<number, Row>,
  goals: {} as Record<number, Row>,
  answers: {} as Record<number, Row>,
  comment: '',
  overall: null as number | null,
})

const mySide = computed(() => (props.side === 'manager' ? 'manager' : 'self'))

function load() {
  const own = mySide.value
  state.kpis = Object.fromEntries(props.review.kpis.map((k) => [k.id, { rating: k[`${own}_rating`], text: '', actual: k.actual === null ? '' : String(k.actual), progress: 0, comment: k.comment ?? '' }]))
  state.goals = Object.fromEntries(props.review.goals.map((g) => [g.id, { rating: g[`${own}_rating`], text: '', actual: '', progress: g.progress, comment: '' }]))
  state.answers = Object.fromEntries(props.review.answers.map((a) => [a.id, { rating: a[`${own}_rating`], text: a[`${own}_answer`] ?? '', actual: '', progress: 0, comment: '' }]))
  state.comment = (own === 'manager' ? props.review.manager_comment : props.review.self_comment) ?? ''
  state.overall = props.review.manager_overall_rating
}

watch(() => props.review, load, { immediate: true })

const hasRatingQuestions = computed(() => props.review.answers.some((a) => a.type === 'rating'))

/** The answers in their sections, in order. */
const sections = computed(() => {
  const groups: { name: string; answers: PerformanceReviewDetail['answers'] }[] = []
  for (const answer of props.review.answers) {
    const name = answer.section ?? ''
    const last = groups[groups.length - 1]
    if (last && last.name === name) last.answers.push(answer)
    else groups.push({ name, answers: [answer] })
  }
  return groups
})

function draft(): ReviewSave {
  const own = mySide.value
  const ratingKey = `${own}_rating` as const
  const save: ReviewSave = {
    kpis: props.review.kpis.map((k) => ({
      id: k.id,
      [ratingKey]: state.kpis[k.id]!.rating,
      actual: state.kpis[k.id]!.actual === '' ? null : Number(state.kpis[k.id]!.actual),
      ...(own === 'manager' ? { comment: state.kpis[k.id]!.comment.trim() || null } : {}),
    })),
    goals: props.review.goals.map((g) => ({ id: g.id, [ratingKey]: state.goals[g.id]!.rating, progress: Number(state.goals[g.id]!.progress) })),
    answers: props.review.answers.map((a) => ({ id: a.id, [ratingKey]: state.answers[a.id]!.rating, [`${own}_answer`]: state.answers[a.id]!.text.trim() || null })),
  }
  if (own === 'manager') {
    save.manager_comment = state.comment.trim() || null
    save.manager_overall_rating = state.overall
  } else {
    save.self_comment = state.comment.trim() || null
  }
  return save
}

defineExpose({ draft })

const target = (k: PerformanceReviewDetail['kpis'][number]) =>
  k.target === null ? '—' : `${k.higher_is_better ? '≥' : '≤'} ${Number(k.target).toLocaleString()}${k.unit ? ` ${k.unit}` : ''}`
const otherLabel = computed(() => (props.side === 'manager' ? t('admin.performance.review.theirRating') : t('admin.performance.review.managerRating')))
const showOther = (other: number | null) => props.side === 'hr' || other !== null
</script>

<template>
  <div class="space-y-6">
    <!-- Scores (once there are some) -->
    <div v-if="review.final_score !== null" class="grid grid-cols-2 gap-3 rounded-lg bg-primary-50 p-3 text-sm sm:grid-cols-5">
      <div class="col-span-2 sm:col-span-1"><p class="text-neutral-600">{{ t('admin.performance.score.final') }}</p><p class="text-lg font-bold text-neutral-900">{{ scoreLabel(review.final_score) }}</p></div>
      <div><p class="text-neutral-600">{{ t('admin.performance.tabs.kpi') }}</p><p class="font-semibold">{{ review.kpi_score?.toFixed(2) ?? '—' }}</p></div>
      <div><p class="text-neutral-600">{{ t('admin.performance.tabs.goals') }}</p><p class="font-semibold">{{ review.goal_score?.toFixed(2) ?? '—' }}</p></div>
      <div><p class="text-neutral-600">{{ t('admin.performance.weights.manager') }}</p><p class="font-semibold">{{ review.manager_score?.toFixed(2) ?? '—' }}</p></div>
      <div><p class="text-neutral-600">{{ t('admin.performance.score.self') }}</p><p class="font-semibold">{{ review.self_score?.toFixed(2) ?? '—' }}</p></div>
    </div>

    <!-- KPIs -->
    <section v-if="review.kpis.length">
      <h3 class="mb-2 text-sm font-semibold text-neutral-800">{{ t('admin.performance.tabs.kpi') }}</h3>
      <div class="space-y-2">
        <div v-for="kpi in review.kpis" :key="kpi.id" class="rounded-lg border border-neutral-200 p-3">
          <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="text-sm font-medium text-neutral-800">{{ kpi.name }}<span v-if="kpi.weight" class="ml-1 text-xs font-normal text-neutral-500">· {{ kpi.weight }}%</span></p>
              <p class="text-xs text-neutral-500">{{ t('admin.performance.kpis.target') }} {{ target(kpi) }}<template v-if="kpi.measurement"> · {{ kpi.measurement }}</template></p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
              <label class="flex items-center gap-1.5 text-xs text-neutral-600">
                {{ t('admin.performance.review.actual') }}
                <input v-if="editable && side !== 'hr'" v-model="state.kpis[kpi.id]!.actual" type="number" class="w-24 rounded-lg border border-neutral-300 px-2 py-1 text-sm" />
                <span v-else class="font-medium text-neutral-800">{{ kpi.actual ?? '—' }}</span>
              </label>
              <StarRating v-if="side !== 'hr'" v-model="state.kpis[kpi.id]!.rating" :readonly="!editable" />
            </div>
          </div>
          <p v-if="side === 'hr'" class="mt-1 flex flex-wrap gap-x-4 text-xs text-neutral-600">
            <span>{{ t('admin.performance.review.theirRating') }} <StarRating :model-value="kpi.self_rating" readonly small /></span>
            <span>{{ t('admin.performance.review.managerRating') }} <StarRating :model-value="kpi.manager_rating" readonly small /></span>
          </p>
          <p v-else-if="showOther(side === 'manager' ? kpi.self_rating : kpi.manager_rating)" class="mt-1 text-xs text-neutral-500">
            {{ otherLabel }} <StarRating :model-value="side === 'manager' ? kpi.self_rating : kpi.manager_rating" readonly small />
          </p>
          <input
            v-if="side === 'manager' && editable"
            v-model="state.kpis[kpi.id]!.comment"
            :placeholder="t('admin.performance.review.kpiComment')"
            class="mt-2 block w-full rounded-lg border border-neutral-300 px-3 py-1.5 text-sm"
          />
          <p v-else-if="kpi.comment" class="mt-1 text-xs text-neutral-600">{{ kpi.comment }}</p>
        </div>
      </div>
    </section>

    <!-- Goals -->
    <section v-if="review.goals.length">
      <h3 class="mb-2 text-sm font-semibold text-neutral-800">{{ t('admin.performance.tabs.goals') }}</h3>
      <div class="space-y-2">
        <div v-for="goal in review.goals" :key="goal.id" class="rounded-lg border border-neutral-200 p-3">
          <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="text-sm font-medium text-neutral-800">{{ goal.title }}<span v-if="goal.weight" class="ml-1 text-xs font-normal text-neutral-500">· {{ goal.weight }}%</span></p>
              <p class="text-xs text-neutral-500"><template v-if="goal.due_date">{{ t('admin.performance.goals.dueOn', { date: formatDate(goal.due_date) }) }} · </template>{{ t(`admin.performance.goals.status.${goal.status}`) }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
              <label class="flex items-center gap-1.5 text-xs text-neutral-600">
                {{ t('admin.performance.goals.progress') }}
                <input v-if="editable && side !== 'hr'" v-model.number="state.goals[goal.id]!.progress" type="number" min="0" max="100" class="w-16 rounded-lg border border-neutral-300 px-2 py-1 text-sm" />
                <span v-else class="font-medium text-neutral-800">{{ goal.progress }}</span>%
              </label>
              <StarRating v-if="side !== 'hr'" v-model="state.goals[goal.id]!.rating" :readonly="!editable" />
            </div>
          </div>
          <p v-if="side === 'hr'" class="mt-1 flex flex-wrap gap-x-4 text-xs text-neutral-600">
            <span>{{ t('admin.performance.review.theirRating') }} <StarRating :model-value="goal.self_rating" readonly small /></span>
            <span>{{ t('admin.performance.review.managerRating') }} <StarRating :model-value="goal.manager_rating" readonly small /></span>
          </p>
          <p v-else-if="showOther(side === 'manager' ? goal.self_rating : goal.manager_rating)" class="mt-1 text-xs text-neutral-500">
            {{ otherLabel }} <StarRating :model-value="side === 'manager' ? goal.self_rating : goal.manager_rating" readonly small />
          </p>
        </div>
      </div>
    </section>

    <!-- The form's questions -->
    <section v-for="(group, index) in sections" :key="index">
      <h3 class="mb-2 text-sm font-semibold text-neutral-800">{{ group.name || t('admin.performance.forms.questions') }}</h3>
      <div class="space-y-2">
        <div v-for="answer in group.answers" :key="answer.id" class="rounded-lg border border-neutral-200 p-3">
          <div class="flex flex-wrap items-start justify-between gap-2">
            <p class="text-sm text-neutral-800">{{ answer.question }}<span v-if="answer.is_required && editable" class="text-red-600"> *</span></p>
            <StarRating v-if="answer.type === 'rating' && side !== 'hr'" v-model="state.answers[answer.id]!.rating" :readonly="!editable" />
          </div>
          <textarea
            v-if="answer.type === 'text' && side !== 'hr' && editable"
            v-model="state.answers[answer.id]!.text"
            rows="2"
            class="mt-2 block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm"
          />
          <p v-else-if="answer.type === 'text' && side !== 'hr'" class="mt-1 whitespace-pre-line text-sm text-neutral-700">{{ state.answers[answer.id]!.text || '—' }}</p>

          <div v-if="side === 'hr'" class="mt-1 grid gap-1 text-xs text-neutral-600 sm:grid-cols-2">
            <p>{{ t('admin.performance.review.theirRating') }}: <StarRating v-if="answer.type === 'rating'" :model-value="answer.self_rating" readonly small /><span v-else class="whitespace-pre-line text-neutral-800">{{ answer.self_answer ?? '—' }}</span></p>
            <p>{{ t('admin.performance.review.managerRating') }}: <StarRating v-if="answer.type === 'rating'" :model-value="answer.manager_rating" readonly small /><span v-else class="whitespace-pre-line text-neutral-800">{{ answer.manager_answer ?? '—' }}</span></p>
          </div>
          <p v-else-if="answer.type === 'rating' && showOther(side === 'manager' ? answer.self_rating : answer.manager_rating)" class="mt-1 text-xs text-neutral-500">
            {{ otherLabel }} <StarRating :model-value="side === 'manager' ? answer.self_rating : answer.manager_rating" readonly small />
          </p>
          <p v-else-if="answer.type === 'text' && (side === 'manager' ? answer.self_answer : answer.manager_answer)" class="mt-1 whitespace-pre-line text-xs text-neutral-500">
            {{ otherLabel }}: {{ side === 'manager' ? answer.self_answer : answer.manager_answer }}
          </p>
        </div>
      </div>
    </section>

    <!-- Overall -->
    <section>
      <template v-if="side === 'hr'">
        <h3 class="mb-1 text-sm font-semibold text-neutral-800">{{ t('admin.performance.review.comments') }}</h3>
        <p class="text-sm text-neutral-700"><span class="text-neutral-500">{{ t('admin.performance.review.theirComment') }}:</span> {{ review.self_comment ?? '—' }}</p>
        <p class="text-sm text-neutral-700"><span class="text-neutral-500">{{ t('admin.performance.review.managerComment') }}:</span> {{ review.manager_comment ?? '—' }}</p>
      </template>
      <template v-else>
        <div v-if="side === 'manager' && !hasRatingQuestions" class="mb-3 flex items-center gap-3">
          <span class="text-sm font-medium text-neutral-700">{{ t('admin.performance.review.overall') }}</span>
          <StarRating v-model="state.overall" :readonly="!editable" />
        </div>
        <label class="mb-1 block text-sm font-semibold text-neutral-800">{{ side === 'manager' ? t('admin.performance.review.managerComment') : t('admin.performance.review.yourComment') }}</label>
        <textarea v-if="editable" v-model="state.comment" rows="3" class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm" />
        <p v-else class="whitespace-pre-line text-sm text-neutral-700">{{ state.comment || '—' }}</p>
        <p v-if="side === 'manager' && review.self_comment" class="mt-2 text-sm text-neutral-600"><span class="text-neutral-500">{{ t('admin.performance.review.theirComment') }}:</span> {{ review.self_comment }}</p>
        <p v-if="side === 'self' && review.manager_comment" class="mt-2 text-sm text-neutral-600"><span class="text-neutral-500">{{ t('admin.performance.review.managerComment') }}:</span> {{ review.manager_comment }}</p>
      </template>
    </section>
  </div>
</template>
