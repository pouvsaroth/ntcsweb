<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { evaluationFormsService, type EvaluationForm, type EvaluationQuestion, type QuestionType } from '@/services/performance'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

/**
 * HRM > Performance Management > Evaluation forms — the questions a review
 * asks the staff member (self-assessment) and their manager, in sections:
 * each rated 1–5, or answered in words. A review cycle picks one form.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => auth.can('performance.manage'))

const { items, meta, loading, error, setPage, fetch } = usePaginatedResource<EvaluationForm>((query) => evaluationFormsService.list(query))
const actionError = ref<string | null>(null)

/** Questions grouped by their section, in order. */
function sections(questions: EvaluationQuestion[]): { name: string; questions: EvaluationQuestion[] }[] {
  const groups: { name: string; questions: EvaluationQuestion[] }[] = []
  for (const question of questions) {
    const name = question.section ?? ''
    const last = groups[groups.length - 1]
    if (last && last.name === name) last.questions.push(question)
    else groups.push({ name, questions: [question] })
  }
  return groups
}

// --- Form ------------------------------------------------------------------------------------

const typeOptions = computed(() => [
  { value: 'rating', label: t('admin.performance.forms.rating') },
  { value: 'text', label: t('admin.performance.forms.text') },
])

const formOpen = ref(false)
const editing = ref<EvaluationForm | null>(null)
const form = reactive({ name: '', description: '', is_active: true, questions: [] as EvaluationQuestion[] })
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

function open(evaluation: EvaluationForm | null) {
  editing.value = evaluation
  form.name = evaluation?.name ?? ''
  form.description = evaluation?.description ?? ''
  form.is_active = evaluation?.is_active ?? true
  form.questions = (evaluation?.questions ?? []).map((q) => ({ ...q }))
  errors.value = {}
  saveError.value = null
  formOpen.value = true
}

/** A new question continues the last one's section. */
function addQuestion() {
  const last = form.questions[form.questions.length - 1]
  form.questions.push({ section: last?.section ?? '', question: '', type: 'rating', is_required: true })
}

function move(index: number, by: number) {
  const to = index + by
  if (to < 0 || to >= form.questions.length) return
  const [question] = form.questions.splice(index, 1)
  form.questions.splice(to, 0, question!)
}

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  const input = {
    name: form.name,
    description: form.description.trim() || null,
    is_active: form.is_active,
    questions: form.questions.map((q) => ({ ...q, section: (q.section ?? '').trim() || null })),
  }
  try {
    if (editing.value) await evaluationFormsService.update(editing.value.id, input)
    else await evaluationFormsService.create(input)
    formOpen.value = false
    await fetch()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.performance.saveFailed')
  } finally {
    saving.value = false
  }
}

async function remove(evaluation: EvaluationForm) {
  if (!(await confirmDialog.confirm({ message: t('admin.performance.deleteConfirm', { name: evaluation.name }), danger: true }))) return
  actionError.value = null
  try {
    await evaluationFormsService.remove(evaluation.id)
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.organization.deleteFailed')
  }
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <p class="text-sm text-neutral-500">{{ t('admin.performance.forms.hint') }}</p>
      <BaseButton v-if="canManage" @click="open(null)">{{ t('admin.performance.forms.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error || actionError" variant="danger" class="mb-4">{{ error || actionError }}</BaseAlert>
    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
    <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('admin.performance.forms.empty') }}</p>
    <div v-else class="grid gap-3 lg:grid-cols-2">
      <div v-for="evaluation in items" :key="evaluation.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
        <div class="flex items-start justify-between gap-2">
          <div>
            <p class="font-semibold text-neutral-800">{{ evaluation.name }}</p>
            <p class="text-xs text-neutral-500">{{ t('admin.performance.forms.questionsN', { count: evaluation.questions.length }) }} · {{ t('admin.performance.forms.cyclesN', { count: evaluation.cycles_count ?? 0 }) }}</p>
          </div>
          <BaseBadge :variant="evaluation.is_active ? 'success' : 'neutral'">{{ evaluation.is_active ? t('admin.organization.statusActive') : t('admin.organization.statusInactive') }}</BaseBadge>
        </div>
        <p v-if="evaluation.description" class="mt-1 text-sm text-neutral-600">{{ evaluation.description }}</p>
        <div v-for="(group, index) in sections(evaluation.questions)" :key="index" class="mt-3">
          <p v-if="group.name" class="text-xs font-semibold uppercase tracking-wide text-neutral-500">{{ group.name }}</p>
          <ol class="mt-1 space-y-0.5 text-sm text-neutral-700">
            <li v-for="question in group.questions" :key="question.id" class="flex items-start gap-2">
              <span class="mt-0.5 shrink-0 text-xs text-neutral-400">{{ question.type === 'rating' ? '★' : '✎' }}</span>
              <span>{{ question.question }}<span v-if="!question.is_required" class="text-xs text-neutral-400"> ({{ t('admin.performance.forms.optional') }})</span></span>
            </li>
          </ol>
        </div>
        <div v-if="canManage" class="mt-3 flex justify-end gap-3 border-t border-neutral-100 pt-2">
          <EditIconButton @click="open(evaluation)" />
          <button type="button" class="text-sm font-medium text-danger-600" @click="remove(evaluation)">{{ t('admin.organization.delete') }}</button>
        </div>
      </div>
    </div>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <BaseModal v-model="formOpen" size="lg" :title="editing ? t('admin.performance.forms.edit') : t('admin.performance.forms.add')">
      <form class="space-y-4" @submit.prevent="save">
        <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
        <BaseInput v-model="form.name" required :label="t('admin.performance.forms.name')" :placeholder="t('admin.performance.forms.namePlaceholder')" :error="errors.name?.[0]" />
        <BaseInput v-model="form.description" :label="t('admin.organization.description')" />

        <section>
          <div class="mb-2 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-neutral-800">{{ t('admin.performance.forms.questions') }}</h3>
            <button type="button" class="text-sm font-medium text-primary-700 hover:underline" @click="addQuestion">+ {{ t('admin.performance.forms.addQuestion') }}</button>
          </div>
          <p v-if="form.questions.length === 0" class="rounded-lg bg-neutral-50 px-3 py-2 text-sm text-neutral-500">{{ t('admin.performance.forms.noQuestions') }}</p>
          <div v-for="(question, index) in form.questions" :key="index" class="mb-3 rounded-lg border border-neutral-200 p-3">
            <div class="grid gap-2 sm:grid-cols-[10rem_minmax(0,1fr)]">
              <BaseInput :model-value="question.section ?? ''" :placeholder="t('admin.performance.forms.section')" @update:model-value="question.section = $event" />
              <BaseInput v-model="question.question" :placeholder="t('admin.performance.forms.question')" :error="errors[`questions.${index}.question`]?.[0]" />
            </div>
            <div class="mt-2 flex flex-wrap items-center gap-3">
              <BaseSelect class="w-48" :model-value="question.type" :options="typeOptions" @update:model-value="question.type = $event as QuestionType" />
              <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input v-model="question.is_required" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
                {{ t('admin.performance.forms.required') }}
              </label>
              <div class="ml-auto flex gap-1">
                <button type="button" class="rounded px-2 py-1 text-sm text-neutral-500 hover:bg-neutral-100" :aria-label="t('admin.performance.forms.moveUp')" @click="move(index, -1)">↑</button>
                <button type="button" class="rounded px-2 py-1 text-sm text-neutral-500 hover:bg-neutral-100" :aria-label="t('admin.performance.forms.moveDown')" @click="move(index, 1)">↓</button>
                <button type="button" class="rounded px-2 py-1 text-sm text-danger-600 hover:bg-red-50" :aria-label="t('admin.organization.delete')" @click="form.questions.splice(index, 1)">✕</button>
              </div>
            </div>
          </div>
        </section>

        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
          {{ t('admin.organization.statusActive') }}
        </label>
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="formOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
