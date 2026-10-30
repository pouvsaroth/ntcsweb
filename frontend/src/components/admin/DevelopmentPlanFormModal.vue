<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import {
  developmentPlansService,
  type DevelopmentPlan,
  type DevelopmentPlanInput,
  type PdStaffOption,
  type PlanOptions,
  type PlanStatus,
} from '@/services/professionalDevelopment'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * Write or edit a staff member's development plan: who, reviewer, period,
 * summary and the goals (each with how, support, an optional training or
 * coaching, and a target date). A new plan can be kept as a draft or shared
 * straight away (the staff member and reviewer are told).
 */
const props = defineProps<{ modelValue: boolean; plan: DevelopmentPlan | null; staff: PdStaffOption[] }>()
const emit = defineEmits<{ 'update:modelValue': [boolean]; saved: [DevelopmentPlan] }>()

const { t } = useI18n()

interface GoalDraft {
  key: number
  id: number | null
  title: string
  area: string
  actions: string
  support: string
  training_id: string
  coaching_id: string
  target_date: string
}

let nextKey = 1
function blankGoal(): GoalDraft {
  return { key: nextKey++, id: null, title: '', area: '', actions: '', support: '', training_id: '', coaching_id: '', target_date: '' }
}

function todayIso(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const form = reactive({ staff_id: '', reviewer_staff_id: '', title: '', start_date: '', end_date: '', summary: '' })
const goals = ref<GoalDraft[]>([])
const options = ref<PlanOptions>({ trainings: [], coachings: [] })
const errors = ref<Record<string, string[]>>({})
const error = ref<string | null>(null)
const saving = ref<PlanStatus | 'save' | null>(null)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    errors.value = {}
    error.value = null
    const p = props.plan
    Object.assign(form, {
      staff_id: p ? String(p.staff_id) : '',
      reviewer_staff_id: p?.reviewer_staff_id != null ? String(p.reviewer_staff_id) : '',
      title: p?.title ?? '',
      start_date: p?.start_date ?? todayIso(),
      end_date: p?.end_date ?? '',
      summary: p?.summary ?? '',
    })
    goals.value = p?.goals?.length
      ? p.goals.map((g) => ({
          key: nextKey++,
          id: g.id,
          title: g.title,
          area: g.area ?? '',
          actions: g.actions ?? '',
          support: g.support ?? '',
          training_id: g.training_id != null ? String(g.training_id) : '',
          coaching_id: g.coaching_id != null ? String(g.coaching_id) : '',
          target_date: g.target_date ?? '',
        }))
      : [blankGoal()]
    void loadOptions()
  },
)

// Coachings depend on who the plan is for.
watch(
  () => form.staff_id,
  (now, before) => {
    if (props.modelValue && before !== undefined && now !== before) void loadOptions()
  },
)

async function loadOptions() {
  options.value = await developmentPlansService.options(form.staff_id ? Number(form.staff_id) : null).catch(() => ({ trainings: [], coachings: [] }))
}

const staffOptions = computed(() => [{ value: '', label: '—' }, ...props.staff.map((s) => ({ value: String(s.id), label: s.name, hint: s.employee_code ?? undefined }))])
const trainingOptions = computed(() => [
  { value: '', label: t('proDev.plans.noLink') },
  ...options.value.trainings.map((tr) => ({ value: String(tr.id), label: tr.title, hint: tr.start_date ? formatDate(tr.start_date) : undefined })),
])
const coachingOptions = computed(() => [
  { value: '', label: t('proDev.plans.noLink') },
  ...options.value.coachings.map((c) => ({ value: String(c.id), label: c.goal, hint: t(`proDev.coachingTypes.${c.type}`) })),
])

function addGoal() {
  goals.value.push(blankGoal())
}
function removeGoal(i: number) {
  goals.value.splice(i, 1)
}
function moveGoal(i: number, by: -1 | 1) {
  const j = i + by
  if (j < 0 || j >= goals.value.length) return
  const list = goals.value
  ;[list[i], list[j]] = [list[j]!, list[i]!]
}

const isDraft = computed(() => !props.plan || props.plan.status === 'draft')

const fieldClass =
  'block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200'

async function save(status: PlanStatus | null) {
  saving.value = status ?? 'save'
  errors.value = {}
  error.value = null
  const input: DevelopmentPlanInput = {
    staff_id: Number(form.staff_id),
    reviewer_staff_id: form.reviewer_staff_id ? Number(form.reviewer_staff_id) : null,
    title: form.title.trim(),
    start_date: form.start_date,
    end_date: form.end_date || null,
    summary: form.summary.trim() || null,
    ...(status ? { status } : {}),
    goals: goals.value
      .filter((g) => g.title.trim() !== '' || g.id != null)
      .map((g) => ({
        id: g.id,
        title: g.title.trim(),
        area: g.area.trim() || null,
        actions: g.actions.trim() || null,
        support: g.support.trim() || null,
        training_id: g.training_id ? Number(g.training_id) : null,
        coaching_id: g.coaching_id ? Number(g.coaching_id) : null,
        target_date: g.target_date || null,
      })),
  }
  try {
    const saved = props.plan ? await developmentPlansService.update(props.plan.id, input) : await developmentPlansService.create(input)
    emit('saved', saved)
    emit('update:modelValue', false)
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) {
      errors.value = e.errors
      if (Object.keys(e.errors).some((k) => k.startsWith('goals.'))) error.value = t('proDev.plans.goalErrors')
    } else error.value = e instanceof ApiRequestError ? e.message : t('proDev.saveFailed')
  } finally {
    saving.value = null
  }
}

function goalError(i: number, field: string): string | undefined {
  return errors.value[`goals.${i}.${field}`]?.[0]
}
</script>

<template>
  <BaseModal :model-value="modelValue" size="lg" :title="plan ? t('proDev.plans.editTitle') : t('proDev.plans.addTitle')" @update:model-value="emit('update:modelValue', $event)">
    <form id="plan-form" class="space-y-4" novalidate @submit.prevent="save(null)">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>

      <div class="grid gap-4 sm:grid-cols-2">
        <SearchableSelect v-model="form.staff_id" required :options="staffOptions" :label="t('proDev.plans.staff')" :error="errors.staff_id?.[0]" />
        <SearchableSelect v-model="form.reviewer_staff_id" :options="staffOptions" :label="t('proDev.plans.reviewer')" :error="errors.reviewer_staff_id?.[0]" />
      </div>
      <BaseInput v-model="form.title" required :label="t('proDev.plans.planTitle')" :placeholder="t('proDev.plans.titlePlaceholder')" :error="errors.title?.[0]" />
      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.start_date" type="date" required :label="t('proDev.plans.startDate')" :error="errors.start_date?.[0]" />
        <BaseInput v-model="form.end_date" type="date" :label="t('proDev.plans.endDate')" :error="errors.end_date?.[0]" />
      </div>
      <div>
        <label for="plan-summary" class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('proDev.plans.summary') }}</label>
        <textarea id="plan-summary" v-model="form.summary" rows="2" :placeholder="t('proDev.plans.summaryPlaceholder')" :class="fieldClass" />
      </div>

      <div>
        <div class="mb-2 flex items-center">
          <h3 class="text-sm font-semibold text-neutral-900">{{ t('proDev.plans.goals') }}</h3>
          <button type="button" class="ml-auto text-sm font-medium text-secondary-700 hover:underline" @click="addGoal">+ {{ t('proDev.plans.addGoal') }}</button>
        </div>
        <p v-if="goals.length === 0" class="rounded-lg border border-dashed border-neutral-300 py-4 text-center text-sm text-neutral-500">{{ t('proDev.plans.noGoals') }}</p>
        <ol class="space-y-3">
          <li v-for="(g, i) in goals" :key="g.key" class="space-y-3 rounded-lg border border-neutral-200 p-3">
            <div class="flex items-center gap-2">
              <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-semibold text-primary-800">{{ i + 1 }}</span>
              <div class="ml-auto flex gap-3 text-sm">
                <button type="button" class="text-neutral-500 hover:text-neutral-800 disabled:opacity-30" :disabled="i === 0" :aria-label="t('proDev.plans.moveUp')" @click="moveGoal(i, -1)">↑</button>
                <button type="button" class="text-neutral-500 hover:text-neutral-800 disabled:opacity-30" :disabled="i === goals.length - 1" :aria-label="t('proDev.plans.moveDown')" @click="moveGoal(i, 1)">↓</button>
                <button type="button" class="font-medium text-danger-600 hover:text-red-700" @click="removeGoal(i)">{{ t('proDev.delete') }}</button>
              </div>
            </div>
            <div class="grid gap-3 sm:grid-cols-3">
              <BaseInput v-model="g.title" class="sm:col-span-2" required :label="t('proDev.plans.goalTitle')" :placeholder="t('proDev.plans.goalPlaceholder')" :error="goalError(i, 'title')" />
              <BaseInput v-model="g.area" :label="t('proDev.plans.area')" :placeholder="t('proDev.plans.areaPlaceholder')" :error="goalError(i, 'area')" />
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
              <div>
                <label :for="`goal-actions-${g.key}`" class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('proDev.plans.actions') }}</label>
                <textarea :id="`goal-actions-${g.key}`" v-model="g.actions" rows="2" :placeholder="t('proDev.plans.actionsPlaceholder')" :class="fieldClass" />
              </div>
              <div>
                <label :for="`goal-support-${g.key}`" class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('proDev.plans.support') }}</label>
                <textarea :id="`goal-support-${g.key}`" v-model="g.support" rows="2" :placeholder="t('proDev.plans.supportPlaceholder')" :class="fieldClass" />
              </div>
            </div>
            <div class="grid gap-3 sm:grid-cols-3">
              <SearchableSelect v-model="g.training_id" :options="trainingOptions" :label="t('proDev.plans.linkedTraining')" :error="goalError(i, 'training_id')" />
              <SearchableSelect v-model="g.coaching_id" :options="coachingOptions" :label="t('proDev.plans.linkedCoaching')" :error="goalError(i, 'coaching_id')" />
              <BaseInput v-model="g.target_date" type="date" :label="t('proDev.plans.targetDate')" :error="goalError(i, 'target_date')" />
            </div>
          </li>
        </ol>
      </div>
    </form>

    <template #footer>
      <div class="flex w-full flex-wrap justify-end gap-2">
        <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</BaseButton>
        <template v-if="isDraft">
          <BaseButton variant="outline" :loading="saving === 'draft'" :disabled="saving !== null" @click="save('draft')">{{ t('proDev.plans.saveDraft') }}</BaseButton>
          <BaseButton :loading="saving === 'active'" :disabled="saving !== null" @click="save('active')">{{ t('proDev.plans.saveShare') }}</BaseButton>
        </template>
        <BaseButton v-else type="submit" form="plan-form" :loading="saving === 'save'">{{ t('common.save') }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
