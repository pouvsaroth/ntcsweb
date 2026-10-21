<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { lessonPlansService, type LessonOptions, type PlanDetail } from '@/services/lessonPlans'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * One lesson plan (docs/academics/lesson-plans.md): class, subject,
 * curriculum lesson, date, objectives, materials, activities, homework,
 * attachments — then submit for review. A reviewer approves or returns it.
 * `planId` null = a new plan (optionally for `date`).
 */
const props = defineProps<{ modelValue: boolean; planId: number | null; options: LessonOptions; date?: string }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; changed: [] }>()

const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canReview = computed(() => auth.can('lesson-plans.review'))

const plan = ref<PlanDetail | null>(null)
const loading = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})
const message = ref<string | null>(null)
const form = reactive({
  staff_id: '', class_id: '', book_id: '', curriculum_lesson_id: '', planned_date: '', title: '',
  objectives: '', materials: '', activities: '', homework: '', homework_due: '',
})
const lessons = ref<{ id: number; title: string }[]>([])

const editable = computed(() => plan.value === null || plan.value.can_edit)
const teacherOptions = computed(() => props.options.teachers.map((x) => ({ value: String(x.id), label: x.name ?? '' })))
const classOptions = computed(() => props.options.classes.map((c) => ({ value: String(c.id), label: c.academic_year ? `${c.name} · ${c.academic_year}` : c.name })))
const subjectOptions = computed(() => [{ value: '', label: '—' }, ...props.options.subjects.map((s) => ({ value: String(s.id), label: s.title }))])
const lessonOptions = computed(() => [{ value: '', label: lessons.value.length ? t('lessonPlans.noCurriculumLesson') : t('lessonPlans.noCurriculum') }, ...lessons.value.map((l) => ({ value: String(l.id), label: l.title }))])
const statusVariant = (s: string) => (s === 'approved' ? 'success' : s === 'submitted' ? 'warning' : s === 'returned' ? 'danger' : 'neutral')

async function loadLessons() {
  lessons.value = form.class_id && form.book_id ? await lessonPlansService.curriculumLessons(Number(form.class_id), Number(form.book_id)).catch(() => []) : []
}
watch(() => [form.class_id, form.book_id], () => void loadLessons())
/** Picking a curriculum lesson fills an empty title. */
watch(
  () => form.curriculum_lesson_id,
  (id) => {
    const l = lessons.value.find((x) => String(x.id) === id)
    if (l && !form.title.trim()) form.title = l.title
  },
)

function fill(p: PlanDetail | null) {
  const v = (x: number | string | null | undefined) => (x === null || x === undefined ? '' : String(x))
  form.staff_id = p ? v(p.staff_id) : v(props.options.me ?? props.options.teachers[0]?.id)
  form.class_id = p ? v(p.class_id) : v(props.options.classes[0]?.id)
  form.book_id = v(p?.book_id)
  form.curriculum_lesson_id = v(p?.curriculum_lesson_id)
  form.planned_date = p?.planned_date ?? props.date ?? ''
  form.title = p?.title ?? ''
  form.objectives = p?.objectives ?? ''
  form.materials = p?.materials ?? ''
  form.activities = p?.activities ?? ''
  form.homework = p?.homework ?? ''
  form.homework_due = p?.homework_due ?? ''
}

async function load(id: number) {
  loading.value = true
  try {
    plan.value = await lessonPlansService.get(id)
    fill(plan.value)
  } catch (e) {
    message.value = e instanceof ApiRequestError ? e.message : t('lessonPlans.loadFailed')
  } finally {
    loading.value = false
  }
}

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    errors.value = {}
    message.value = null
    reviewNote.value = ''
    plan.value = null
    if (props.planId) void load(props.planId)
    else fill(null)
  },
)

const input = () => ({
  staff_id: form.staff_id ? Number(form.staff_id) : null,
  class_id: Number(form.class_id),
  book_id: form.book_id ? Number(form.book_id) : null,
  curriculum_lesson_id: form.curriculum_lesson_id ? Number(form.curriculum_lesson_id) : null,
  planned_date: form.planned_date,
  title: form.title.trim(),
  objectives: form.objectives.trim() || null,
  materials: form.materials.trim() || null,
  activities: form.activities.trim() || null,
  homework: form.homework.trim() || null,
  homework_due: form.homework_due || null,
})

async function save(thenSubmit = false) {
  saving.value = true
  errors.value = {}
  try {
    let id = plan.value?.id ?? null
    if (id) await lessonPlansService.update(id, input())
    else id = (await lessonPlansService.create(input())).id
    if (thenSubmit) await lessonPlansService.submit(id)
    emit('changed')
    await load(id)
    message.value = thenSubmit ? t('lessonPlans.submitted') : t('lessonPlans.saved')
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else message.value = e instanceof ApiRequestError ? e.message : t('lessonPlans.saveFailed')
  } finally {
    saving.value = false
  }
}

async function remove() {
  if (!plan.value || !(await confirmDialog.confirm({ message: t('lessonPlans.deleteConfirm'), danger: true }))) return
  await lessonPlansService.remove(plan.value.id).catch(() => undefined)
  emit('changed')
  emit('update:modelValue', false)
}

// --- Attachments ---------------------------------------------------------------------
const uploading = ref(false)
async function onFile(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file || !plan.value) return
  uploading.value = true
  try {
    await lessonPlansService.upload(plan.value.id, file)
    await load(plan.value.id)
    emit('changed')
  } catch (err) {
    message.value = err instanceof ApiRequestError ? (err.errors ? (Object.values(err.errors)[0]?.[0] ?? err.message) : err.message) : t('lessonPlans.saveFailed')
  } finally {
    uploading.value = false
    input.value = ''
  }
}
async function removeFile(id: number) {
  if (!plan.value) return
  await lessonPlansService.removeFile(id).catch(() => undefined)
  await load(plan.value.id)
}
const size = (n: number) => (n >= 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`)

// --- Review ------------------------------------------------------------------------
const reviewNote = ref('')
async function review(decision: 'approve' | 'return') {
  if (!plan.value) return
  saving.value = true
  errors.value = {}
  try {
    await lessonPlansService.review(plan.value.id, decision, reviewNote.value.trim() || null)
    emit('changed')
    await load(plan.value.id)
    message.value = decision === 'approve' ? t('lessonPlans.review.approved') : t('lessonPlans.review.returned')
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else message.value = e instanceof ApiRequestError ? e.message : t('lessonPlans.saveFailed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" size="lg" :title="plan ? plan.title : t('lessonPlans.newPlan')" @update:model-value="emit('update:modelValue', $event)">
    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
    <form v-else class="space-y-4" @submit.prevent="save(false)">
      <div v-if="plan" class="flex flex-wrap items-center gap-2 text-sm">
        <BaseBadge :variant="statusVariant(plan.status)">{{ t(`lessonPlans.status.${plan.status}`) }}</BaseBadge>
        <span class="text-neutral-500">{{ plan.teacher }} · {{ plan.class }}</span>
        <span v-if="plan.taught" class="text-green-700">✓ {{ t('lessonPlans.taught') }}</span>
      </div>
      <BaseAlert v-if="message" variant="info">{{ message }}</BaseAlert>
      <BaseAlert v-if="plan?.review_note" :variant="plan.status === 'returned' ? 'warning' : 'info'">
        <p class="text-xs font-medium">{{ t('lessonPlans.review.note', { name: plan.reviewed_by ?? '' }) }}</p>
        <p class="text-sm">{{ plan.review_note }}</p>
      </BaseAlert>

      <fieldset :disabled="!editable" class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseSelect v-if="options.sees_all" v-model="form.staff_id" :options="teacherOptions" :placeholder="t('lessonPlans.chooseTeacher')" required :label="t('lessonPlans.teacher')" :error="errors.staff_id?.[0]" />
          <BaseSelect v-model="form.class_id" :options="classOptions" :placeholder="t('lessonPlans.chooseClass')" required :label="t('lessonPlans.class')" :error="errors.class_id?.[0]" />
          <BaseSelect v-model="form.book_id" :options="subjectOptions" :label="t('lessonPlans.subject')" :error="errors.book_id?.[0]" />
          <BaseSelect v-model="form.curriculum_lesson_id" :options="lessonOptions" :label="t('lessonPlans.curriculumLesson')" :disabled="!form.book_id" />
          <BaseInput v-model="form.planned_date" type="date" required :label="t('lessonPlans.date')" :error="errors.planned_date?.[0]" />
        </div>
        <BaseInput v-model="form.title" required :label="t('lessonPlans.title')" :error="errors.title?.[0]" />
        <div>
          <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('lessonPlans.objectives') }}</label>
          <textarea v-model="form.objectives" rows="3" :placeholder="t('lessonPlans.objectivesHint')" class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none disabled:bg-neutral-50" />
        </div>
        <div>
          <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('lessonPlans.materials') }}</label>
          <textarea v-model="form.materials" rows="2" :placeholder="t('lessonPlans.materialsHint')" class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none disabled:bg-neutral-50" />
        </div>
        <div>
          <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('lessonPlans.activities') }}</label>
          <textarea v-model="form.activities" rows="4" :placeholder="t('lessonPlans.activitiesHint')" class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none disabled:bg-neutral-50" />
        </div>
        <div class="grid gap-4 sm:grid-cols-[1fr_12rem]">
          <div>
            <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('lessonPlans.homework') }}</label>
            <textarea v-model="form.homework" rows="2" class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none disabled:bg-neutral-50" />
          </div>
          <BaseInput v-model="form.homework_due" type="date" :label="t('lessonPlans.homeworkDue')" :error="errors.homework_due?.[0]" />
        </div>
      </fieldset>

      <!-- Attachments (once saved) -->
      <div class="rounded-lg border border-neutral-200 p-3">
        <div class="flex items-center justify-between gap-2">
          <p class="text-sm font-medium text-neutral-700">{{ t('lessonPlans.files') }}</p>
          <label v-if="plan && editable" class="cursor-pointer text-sm font-medium text-secondary-700 hover:underline">
            {{ uploading ? t('lessonPlans.uploading') : t('lessonPlans.attach') }}
            <input type="file" class="hidden" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt" @change="onFile" />
          </label>
        </div>
        <p v-if="!plan" class="mt-1 text-xs text-neutral-500">{{ t('lessonPlans.filesAfterSave') }}</p>
        <p v-else-if="plan.files.length === 0" class="mt-1 text-xs text-neutral-500">{{ t('lessonPlans.noFiles') }}</p>
        <ul v-else class="mt-2 space-y-1">
          <li v-for="f in plan.files" :key="f.id" class="flex items-center gap-2 text-sm">
            <button type="button" class="min-w-0 flex-1 truncate text-left text-primary-700 hover:underline" @click="lessonPlansService.download(f)">{{ f.name }}</button>
            <span class="text-xs text-neutral-400">{{ size(f.size) }}</span>
            <button v-if="editable" type="button" class="text-xs text-danger-600" @click="removeFile(f.id)">{{ t('lessonPlans.delete') }}</button>
          </li>
        </ul>
      </div>

      <div v-if="plan && plan.logs.length" class="text-xs text-neutral-500">
        {{ t('lessonPlans.taughtOn') }}: <span v-for="(l, i) in plan.logs" :key="l.id">{{ i ? ', ' : '' }}{{ formatDate(l.taught_on) }} ({{ t(`lessonPlans.logStatus.${l.status}`) }})</span>
      </div>

      <!-- Review -->
      <div v-if="plan && canReview && plan.status === 'submitted'" class="space-y-2 rounded-lg bg-amber-50 p-3">
        <p class="text-sm font-medium text-neutral-800">{{ t('lessonPlans.review.title') }}</p>
        <BaseInput v-model="reviewNote" :label="t('lessonPlans.review.comment')" :hint="t('lessonPlans.review.commentHint')" :error="errors.note?.[0]" />
        <div class="flex justify-end gap-2">
          <BaseButton variant="outline" :loading="saving" @click="review('return')">{{ t('lessonPlans.review.return') }}</BaseButton>
          <BaseButton :loading="saving" @click="review('approve')">{{ t('lessonPlans.review.approve') }}</BaseButton>
        </div>
      </div>
    </form>

    <template #footer>
      <BaseButton v-if="plan && editable" variant="danger" class="mr-auto" @click="remove">{{ t('lessonPlans.delete') }}</BaseButton>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <template v-if="editable">
        <BaseButton variant="outline" :loading="saving" @click="save(false)">{{ t('lessonPlans.saveDraft') }}</BaseButton>
        <BaseButton :loading="saving" @click="save(true)">{{ t('lessonPlans.submit') }}</BaseButton>
      </template>
    </template>
  </BaseModal>
</template>
