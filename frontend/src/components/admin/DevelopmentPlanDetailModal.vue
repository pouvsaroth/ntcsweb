<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { GOAL_STATUSES, developmentPlansService, type DevelopmentPlan, type GoalStatus, type PlanGoal, type PlanStatus } from '@/services/professionalDevelopment'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'
import { goalStatusVariant, planStatusVariant } from '@/utils/professionalDevelopment'

/**
 * One development plan: its goals and their progress. The staff member, the
 * reviewer or a manager moves a goal along; the reviewer (or a manager)
 * writes the review and closes the plan; a manager edits, shares or deletes.
 */
const props = defineProps<{ modelValue: boolean; planId: number | null }>()
const emit = defineEmits<{ 'update:modelValue': [boolean]; changed: []; edit: [DevelopmentPlan] }>()

const { t } = useI18n()
const confirmDialog = useConfirmDialogStore()

const plan = ref<DevelopmentPlan | null>(null)
const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)

async function load() {
  if (props.planId == null) return
  loading.value = true
  error.value = null
  try {
    plan.value = await developmentPlansService.get(props.planId)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('proDev.loadFailed')
  } finally {
    loading.value = false
  }
}

watch(
  () => props.modelValue,
  (open) => {
    editingGoal.value = null
    reviewing.value = false
    if (open) void load()
    else plan.value = null
  },
)

// --- Update one goal's progress ------------------------------------------------
const editingGoal = ref<number | null>(null)
const progress = reactive({ status: 'not_started' as GoalStatus, progress: 0, staff_note: '' })

function openProgress(g: PlanGoal) {
  editingGoal.value = g.id
  Object.assign(progress, { status: g.status, progress: g.progress, staff_note: g.staff_note ?? '' })
}

async function saveProgress(g: PlanGoal) {
  saving.value = true
  error.value = null
  try {
    plan.value = await developmentPlansService.progress(g.id, {
      status: progress.status,
      progress: progress.progress,
      staff_note: progress.staff_note.trim() || null,
    })
    editingGoal.value = null
    emit('changed')
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('proDev.saveFailed')
  } finally {
    saving.value = false
  }
}

// --- Review and status ---------------------------------------------------------
const reviewing = ref(false)
const reviewNote = ref('')
function startReview() {
  reviewNote.value = plan.value?.review_note ?? ''
  reviewing.value = true
}

async function setStatus(status: PlanStatus) {
  if (!plan.value) return
  if (status === 'cancelled' && !(await confirmDialog.confirm({ message: t('proDev.plans.cancelConfirm'), danger: true }))) return
  saving.value = true
  error.value = null
  try {
    plan.value = await developmentPlansService.update(plan.value.id, { status, ...(status === 'completed' ? { review_note: reviewNote.value.trim() || null } : {}) })
    reviewing.value = false
    emit('changed')
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('proDev.saveFailed')
  } finally {
    saving.value = false
  }
}

async function remove() {
  if (!plan.value) return
  if (!(await confirmDialog.confirm({ message: t('proDev.plans.deleteConfirm'), danger: true }))) return
  try {
    await developmentPlansService.remove(plan.value.id)
    emit('changed')
    emit('update:modelValue', false)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('proDev.deleteFailed')
  }
}

const fieldClass =
  'block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200'
</script>

<template>
  <BaseModal :model-value="modelValue" size="lg" :title="plan?.title ?? t('proDev.tabs.plans')" @update:model-value="emit('update:modelValue', $event)">
    <div v-if="loading && !plan" class="flex justify-center py-10"><BaseSpinner /></div>

    <div v-else-if="plan" class="space-y-5">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>
      <BaseAlert v-if="plan.status === 'draft'" variant="info">{{ t('proDev.plans.draftNote') }}</BaseAlert>

      <div class="flex flex-wrap items-center gap-2">
        <BaseBadge :variant="planStatusVariant(plan.status)">{{ t(`proDev.planStatuses.${plan.status}`) }}</BaseBadge>
      </div>

      <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
        <div>
          <dt class="text-neutral-500">{{ t('proDev.plans.staff') }}</dt>
          <dd class="font-medium text-neutral-900">{{ plan.staff?.name ?? '—' }}</dd>
        </div>
        <div>
          <dt class="text-neutral-500">{{ t('proDev.plans.reviewer') }}</dt>
          <dd class="font-medium text-neutral-900">{{ plan.reviewer?.name ?? '—' }}</dd>
        </div>
        <div>
          <dt class="text-neutral-500">{{ t('proDev.plans.period') }}</dt>
          <dd class="font-medium text-neutral-900">{{ formatDate(plan.start_date) }}<template v-if="plan.end_date"> – {{ formatDate(plan.end_date) }}</template></dd>
        </div>
        <div>
          <dt class="text-neutral-500">{{ t('proDev.plans.progress') }}</dt>
          <dd class="flex items-center gap-2">
            <div class="h-2 flex-1 overflow-hidden rounded-full bg-neutral-200" role="progressbar" :aria-valuenow="plan.progress" aria-valuemin="0" aria-valuemax="100">
              <div class="h-full rounded-full bg-primary-500" :style="{ width: `${plan.progress}%` }" />
            </div>
            <span class="text-sm font-medium text-neutral-900">{{ plan.progress }}%</span>
          </dd>
        </div>
      </dl>
      <p v-if="plan.summary" class="whitespace-pre-line rounded-lg bg-neutral-50 px-3 py-2 text-sm text-neutral-700">{{ plan.summary }}</p>

      <div>
        <h3 class="mb-2 text-sm font-semibold text-neutral-900">{{ t('proDev.plans.goals') }} · {{ t('proDev.plans.doneOf', { done: plan.goals_done, total: plan.goals_count }) }}</h3>
        <p v-if="(plan.goals ?? []).length === 0" class="rounded-lg border border-dashed border-neutral-300 py-6 text-center text-sm text-neutral-500">{{ t('proDev.plans.noGoals') }}</p>
        <ol v-else class="space-y-2">
          <li v-for="(g, i) in plan.goals" :key="g.id" class="rounded-lg border p-3" :class="g.overdue ? 'border-amber-300 bg-amber-50/40' : 'border-neutral-200'">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="font-medium text-neutral-900">{{ i + 1 }}. {{ g.title }}</p>
                <p class="text-xs text-neutral-500">
                  {{ [g.area, g.target_date ? t('proDev.plans.targetOn', { date: formatDate(g.target_date) }) : null].filter(Boolean).join(' · ') }}
                  <span v-if="g.overdue" class="font-medium text-amber-700"> · {{ t('proDev.plans.overdue') }}</span>
                </p>
              </div>
              <div class="flex items-center gap-2">
                <BaseBadge :variant="goalStatusVariant(g.status)">{{ t(`proDev.goalStatuses.${g.status}`) }}</BaseBadge>
                <span class="text-sm font-medium text-neutral-700">{{ g.progress }}%</span>
              </div>
            </div>
            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-neutral-200">
              <div class="h-full rounded-full" :class="g.status === 'done' ? 'bg-green-500' : 'bg-primary-500'" :style="{ width: `${g.progress}%` }" />
            </div>
            <dl v-if="g.actions || g.support || g.training || g.coaching" class="mt-2 space-y-1 text-sm">
              <div v-if="g.actions"><dt class="inline text-neutral-500">{{ t('proDev.plans.actions') }}:</dt> <dd class="inline whitespace-pre-line text-neutral-700">{{ g.actions }}</dd></div>
              <div v-if="g.support"><dt class="inline text-neutral-500">{{ t('proDev.plans.support') }}:</dt> <dd class="inline whitespace-pre-line text-neutral-700">{{ g.support }}</dd></div>
              <div v-if="g.training">
                <dt class="inline text-neutral-500">{{ t('proDev.plans.linkedTraining') }}:</dt>
                <dd class="inline text-neutral-700">{{ g.training.title }}<template v-if="g.training.start_date"> ({{ formatDate(g.training.start_date) }}, {{ t(`proDev.statuses.${g.training.status}`) }})</template></dd>
              </div>
              <div v-if="g.coaching">
                <dt class="inline text-neutral-500">{{ t('proDev.plans.linkedCoaching') }}:</dt>
                <dd class="inline text-neutral-700">{{ g.coaching.goal }} ({{ t(`proDev.coachingStatuses.${g.coaching.status}`) }})</dd>
              </div>
            </dl>
            <p v-if="g.staff_note && editingGoal !== g.id" class="mt-2 whitespace-pre-line rounded bg-neutral-50 px-2 py-1 text-sm text-neutral-700">
              <span class="font-medium">{{ t('proDev.plans.note') }}:</span> {{ g.staff_note }}
            </p>

            <form v-if="editingGoal === g.id" class="mt-3 space-y-3 rounded-lg border border-primary-200 bg-primary-50/40 p-3" novalidate @submit.prevent="saveProgress(g)">
              <div class="grid gap-3 sm:grid-cols-2">
                <div>
                  <label :for="`goal-status-${g.id}`" class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('proDev.trainings.status') }}</label>
                  <select :id="`goal-status-${g.id}`" v-model="progress.status" :class="fieldClass">
                    <option v-for="s in GOAL_STATUSES" :key="s" :value="s">{{ t(`proDev.goalStatuses.${s}`) }}</option>
                  </select>
                </div>
                <div v-if="progress.status === 'in_progress'">
                  <label :for="`goal-progress-${g.id}`" class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('proDev.plans.progress') }}: {{ progress.progress }}%</label>
                  <input :id="`goal-progress-${g.id}`" v-model.number="progress.progress" type="range" min="0" max="100" step="10" class="w-full accent-primary-500" />
                </div>
              </div>
              <div>
                <label :for="`goal-note-${g.id}`" class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('proDev.plans.note') }}</label>
                <textarea :id="`goal-note-${g.id}`" v-model="progress.staff_note" rows="2" :placeholder="t('proDev.plans.notePlaceholder')" :class="fieldClass" />
              </div>
              <div class="flex justify-end gap-2">
                <BaseButton variant="outline" size="sm" @click="editingGoal = null">{{ t('common.cancel') }}</BaseButton>
                <BaseButton type="submit" size="sm" :loading="saving">{{ t('common.save') }}</BaseButton>
              </div>
            </form>
            <div v-else-if="plan.can.progress" class="mt-2 text-right">
              <button type="button" class="text-sm font-medium text-secondary-700 hover:underline" @click="openProgress(g)">{{ t('proDev.plans.updateProgress') }}</button>
            </div>
          </li>
        </ol>
      </div>

      <div v-if="plan.review_note && !reviewing" class="rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-sm">
        <p class="font-medium text-green-800">{{ t('proDev.plans.review') }}</p>
        <p class="mt-1 whitespace-pre-line text-green-900">{{ plan.review_note }}</p>
      </div>
      <div v-if="reviewing" class="space-y-2 rounded-lg border border-green-200 bg-green-50 p-3">
        <label for="plan-review" class="block text-sm font-medium text-green-900">{{ t('proDev.plans.review') }}</label>
        <textarea id="plan-review" v-model="reviewNote" rows="3" :placeholder="t('proDev.plans.reviewPlaceholder')" :class="fieldClass" />
        <div class="flex justify-end gap-2">
          <BaseButton variant="outline" size="sm" @click="reviewing = false">{{ t('common.cancel') }}</BaseButton>
          <BaseButton size="sm" :loading="saving" @click="setStatus('completed')">{{ t('proDev.plans.complete') }}</BaseButton>
        </div>
      </div>
    </div>

    <template #footer>
      <div v-if="plan" class="flex w-full flex-wrap justify-end gap-2">
        <template v-if="plan.can.manage">
          <BaseButton variant="outline" class="mr-auto text-danger-600" @click="remove">{{ t('proDev.delete') }}</BaseButton>
          <BaseButton variant="outline" @click="emit('edit', plan)">{{ t('proDev.edit') }}</BaseButton>
          <BaseButton v-if="plan.status === 'active'" variant="outline" :loading="saving" @click="setStatus('cancelled')">{{ t('proDev.plans.cancel') }}</BaseButton>
          <BaseButton v-if="plan.status === 'draft'" :loading="saving" @click="setStatus('active')">{{ t('proDev.plans.share') }}</BaseButton>
        </template>
        <template v-if="plan.can.review">
          <BaseButton v-if="plan.status === 'active' && !reviewing" @click="startReview">{{ t('proDev.plans.complete') }}</BaseButton>
          <BaseButton v-if="plan.status === 'completed' || (plan.status === 'cancelled' && plan.can.manage)" variant="outline" :loading="saving" @click="setStatus('active')">
            {{ t('proDev.plans.reopen') }}
          </BaseButton>
        </template>
        <BaseButton v-if="!plan.can.manage && !plan.can.review" variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
