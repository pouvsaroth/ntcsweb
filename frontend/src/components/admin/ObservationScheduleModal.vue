<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { observationsService, type Observation, type ObservationInput, type ObservationOptions } from '@/services/professionalDevelopment'
import { ApiRequestError } from '@/types/api'

/**
 * Schedule a classroom observation, or change one: the teacher, the class
 * and subject (classes of that teacher first), the form, the observer and
 * when. Teacher and observer are notified.
 */
const props = defineProps<{ modelValue: boolean; observation: Observation | null; options: ObservationOptions | null }>()
const emit = defineEmits<{ 'update:modelValue': [boolean]; saved: [Observation] }>()

const { t } = useI18n()

function todayIso(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const form = reactive({
  staff_id: '',
  observer_staff_id: '',
  class_id: '',
  book_id: '',
  observation_form_id: '',
  observed_on: '',
  start_time: '',
  end_time: '',
  focus: '',
})
const errors = ref<Record<string, string[]>>({})
const error = ref<string | null>(null)
const saving = ref(false)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    errors.value = {}
    error.value = null
    const o = props.observation
    const str = (v: number | null | undefined) => (v ? String(v) : '')
    Object.assign(form, {
      staff_id: str(o?.staff_id),
      observer_staff_id: str(o?.observer_staff_id),
      class_id: str(o?.class_id),
      book_id: str(o?.book_id),
      observation_form_id: str(o?.observation_form_id) || (props.options?.forms.length === 1 ? String(props.options.forms[0]!.id) : ''),
      observed_on: o?.observed_on ?? todayIso(),
      start_time: o?.start_time ?? '',
      end_time: o?.end_time ?? '',
      focus: o?.focus ?? '',
    })
  },
)

const people = (list: { id: number; name: string; employee_code: string | null }[], blank: string) => [
  { value: '', label: blank },
  ...list.map((p) => ({ value: String(p.id), label: p.name, hint: p.employee_code ?? undefined })),
]
const teacherOptions = computed(() => people(props.options?.teachers ?? [], '—'))
const observerOptions = computed(() => people((props.options?.observers ?? []).filter((p) => String(p.id) !== form.staff_id), '—'))
const formOptions = computed(() => [{ value: '', label: '—' }, ...(props.options?.forms ?? []).map((f) => ({ value: String(f.id), label: f.name, hint: t('proDev.forms.criteriaCount', { n: f.criteria_count }) }))])
const subjectOptions = computed(() => [{ value: '', label: '—' }, ...(props.options?.subjects ?? []).map((s) => ({ value: String(s.id), label: s.title }))])

// The chosen teacher's classes first, marked; then every other class.
const classOptions = computed(() => {
  const teacherId = Number(form.staff_id)
  const classes = props.options?.classes ?? []
  const theirs = classes.filter((c) => c.teacher_ids.includes(teacherId))
  const others = classes.filter((c) => !c.teacher_ids.includes(teacherId))
  return [
    { value: '', label: '—' },
    ...theirs.map((c) => ({ value: String(c.id), label: c.name, hint: t('proDev.observations.theirClass') })),
    ...others.map((c) => ({ value: String(c.id), label: c.name })),
  ]
})

const noForms = computed(() => props.options !== null && props.options.forms.length === 0)

async function save() {
  saving.value = true
  errors.value = {}
  error.value = null
  const id = (v: string) => (v ? Number(v) : null)
  const input: ObservationInput = {
    staff_id: Number(form.staff_id),
    observer_staff_id: id(form.observer_staff_id),
    class_id: id(form.class_id),
    book_id: id(form.book_id),
    observation_form_id: Number(form.observation_form_id),
    observed_on: form.observed_on,
    start_time: form.start_time || null,
    end_time: form.end_time || null,
    focus: form.focus.trim() || null,
  }
  try {
    const saved = props.observation ? await observationsService.update(props.observation.id, input) : await observationsService.create(input)
    emit('saved', saved)
    emit('update:modelValue', false)
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else error.value = e instanceof ApiRequestError ? e.message : t('proDev.saveFailed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    size="lg"
    :title="observation ? t('proDev.observations.editTitle') : t('proDev.observations.addTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form id="observation-schedule-form" class="space-y-4" novalidate @submit.prevent="save">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>
      <BaseAlert v-if="noForms" variant="warning">{{ t('proDev.observations.noForms') }}</BaseAlert>

      <div class="grid gap-4 sm:grid-cols-2">
        <SearchableSelect v-model="form.staff_id" required :options="teacherOptions" :label="t('proDev.observations.teacher')" :error="errors.staff_id?.[0]" />
        <SearchableSelect v-model="form.observer_staff_id" :options="observerOptions" :label="t('proDev.observations.observer')" :hint="t('proDev.observations.observerHint')" :error="errors.observer_staff_id?.[0]" />
      </div>
      <div class="grid gap-4 sm:grid-cols-2">
        <SearchableSelect v-model="form.class_id" :options="classOptions" :label="t('proDev.observations.class')" :error="errors.class_id?.[0]" />
        <SearchableSelect v-model="form.book_id" :options="subjectOptions" :label="t('proDev.observations.subject')" :error="errors.book_id?.[0]" />
      </div>
      <SearchableSelect v-model="form.observation_form_id" required :options="formOptions" :label="t('proDev.observations.form')" :error="errors.observation_form_id?.[0]" />
      <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
        <BaseInput v-model="form.observed_on" class="col-span-2 sm:col-span-1" type="date" required :label="t('proDev.observations.date')" :error="errors.observed_on?.[0]" />
        <BaseInput v-model="form.start_time" type="time" :label="t('proDev.trainings.startTime')" :error="errors.start_time?.[0]" />
        <BaseInput v-model="form.end_time" type="time" :label="t('proDev.trainings.endTime')" :error="errors.end_time?.[0]" />
      </div>
      <BaseInput v-model="form.focus" :label="t('proDev.observations.focus')" :placeholder="t('proDev.observations.focusPlaceholder')" :error="errors.focus?.[0]" />
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</BaseButton>
      <BaseButton type="submit" form="observation-schedule-form" :loading="saving" :disabled="noForms">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
