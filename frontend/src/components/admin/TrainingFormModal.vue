<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseMultiSelect from '@/components/ui/BaseMultiSelect.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import {
  PROVIDER_TYPES,
  TRAINING_MODES,
  TRAINING_STATUSES,
  trainingsService,
  type PdStaffOption,
  type ProviderType,
  type Training,
  type TrainingInput,
  type TrainingMode,
  type TrainingStatus,
} from '@/services/professionalDevelopment'
import { ApiRequestError } from '@/types/api'

/**
 * Plan a training, or edit one: what, who runs it, where and when, and the
 * staff assigned. `preset` opens it for recording a past / outside course
 * (completed, external), optionally for one person.
 */
const props = defineProps<{
  modelValue: boolean
  training: Training | null
  staff: PdStaffOption[]
  categories: string[]
  preset?: { status?: TrainingStatus; provider_type?: ProviderType; staff_id?: number | null; start_date?: string } | null
}>()
const emit = defineEmits<{ 'update:modelValue': [boolean]; saved: [Training] }>()

const { t } = useI18n()

function todayIso(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const form = reactive({
  title: '',
  category: '',
  provider_type: 'internal' as ProviderType,
  trainer: '',
  mode: 'in_person' as TrainingMode,
  location: '',
  start_date: '',
  end_date: '',
  start_time: '',
  end_time: '',
  hours: '',
  cost: '',
  description: '',
  status: 'planned' as TrainingStatus,
  participant_ids: [] as string[],
})
const errors = ref<Record<string, string[]>>({})
const error = ref<string | null>(null)
const saving = ref(false)
const teachersOnly = ref(false)

watch(
  () => props.modelValue,
  async (open) => {
    if (!open) return
    errors.value = {}
    error.value = null
    teachersOnly.value = false
    let tr = props.training
    if (tr && !tr.participants) {
      try {
        tr = await trainingsService.get(tr.id)
      } catch {
        // Fall back to what the row has.
      }
    }
    const start = props.preset?.start_date ?? todayIso()
    Object.assign(form, {
      title: tr?.title ?? '',
      category: tr?.category ?? '',
      provider_type: tr?.provider_type ?? props.preset?.provider_type ?? 'internal',
      trainer: tr?.trainer ?? '',
      mode: tr?.mode ?? 'in_person',
      location: tr?.location ?? '',
      start_date: tr?.start_date ?? start,
      end_date: tr?.end_date ?? start,
      start_time: tr?.start_time ?? '',
      end_time: tr?.end_time ?? '',
      hours: tr?.hours != null ? String(tr.hours) : '',
      cost: tr?.cost != null ? String(tr.cost) : '',
      description: tr?.description ?? '',
      status: tr?.status ?? props.preset?.status ?? 'planned',
      participant_ids: tr ? (tr.participants ?? []).map((p) => String(p.staff_id)) : props.preset?.staff_id ? [String(props.preset.staff_id)] : [],
    })
  },
)

// Keep the end date from falling before the start date.
watch(
  () => form.start_date,
  (start) => {
    if (start && (!form.end_date || form.end_date < start)) form.end_date = start
  },
)

const participantOptions = computed(() =>
  props.staff
    .filter((s) => !teachersOnly.value || s.is_teacher || form.participant_ids.includes(String(s.id)))
    .map((s) => ({ value: String(s.id), label: s.employee_code ? `${s.name} (${s.employee_code})` : s.name })),
)
const providerOptions = computed(() => PROVIDER_TYPES.map((v) => ({ value: v, label: t(`proDev.providers.${v}`) })))
const modeOptions = computed(() => TRAINING_MODES.map((v) => ({ value: v, label: t(`proDev.modes.${v}`) })))
const statusOptions = computed(() => TRAINING_STATUSES.map((v) => ({ value: v, label: t(`proDev.statuses.${v}`) })))

function selectAllTeachers() {
  const ids = new Set(form.participant_ids)
  for (const s of props.staff) if (s.is_teacher) ids.add(String(s.id))
  form.participant_ids = [...ids]
}

const fieldClass =
  'block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200'

const num = (v: string) => (v.trim() === '' ? null : Number(v))

async function save() {
  saving.value = true
  errors.value = {}
  error.value = null
  const input: TrainingInput = {
    title: form.title.trim(),
    category: form.category.trim() || null,
    provider_type: form.provider_type,
    trainer: form.trainer.trim() || null,
    mode: form.mode,
    location: form.location.trim() || null,
    start_date: form.start_date,
    end_date: form.end_date,
    start_time: form.start_time || null,
    end_time: form.end_time || null,
    hours: num(form.hours),
    cost: num(form.cost),
    description: form.description.trim() || null,
    status: form.status,
    participant_ids: form.participant_ids.map(Number),
  }
  try {
    const saved = props.training ? await trainingsService.update(props.training.id, input) : await trainingsService.create(input)
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
    :title="training ? t('proDev.trainings.editTitle') : t('proDev.trainings.addTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form id="training-form" class="space-y-4" novalidate @submit.prevent="save">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>

      <BaseInput v-model="form.title" required :label="t('proDev.trainings.title')" :placeholder="t('proDev.trainings.titlePlaceholder')" :error="errors.title?.[0]" />

      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label for="training-category" class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('proDev.trainings.category') }}</label>
          <input id="training-category" v-model="form.category" list="training-categories" :placeholder="t('proDev.trainings.categoryPlaceholder')" :class="fieldClass" />
          <p v-if="errors.category?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.category[0] }}</p>
          <datalist id="training-categories">
            <option v-for="c in categories" :key="c" :value="c" />
          </datalist>
        </div>
        <BaseSelect v-model="form.status" :options="statusOptions" :label="t('proDev.trainings.status')" :error="errors.status?.[0]" />
      </div>

      <div class="grid gap-4 sm:grid-cols-3">
        <BaseSelect v-model="form.provider_type" :options="providerOptions" :label="t('proDev.trainings.provider')" />
        <BaseInput v-model="form.trainer" class="sm:col-span-2" :label="t('proDev.trainings.trainer')" :placeholder="t('proDev.trainings.trainerPlaceholder')" :error="errors.trainer?.[0]" />
      </div>

      <div class="grid gap-4 sm:grid-cols-3">
        <BaseSelect v-model="form.mode" :options="modeOptions" :label="t('proDev.trainings.mode')" />
        <BaseInput v-model="form.location" class="sm:col-span-2" :label="t('proDev.trainings.location')" :error="errors.location?.[0]" />
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.start_date" type="date" required :label="t('proDev.trainings.startDate')" :error="errors.start_date?.[0]" />
        <BaseInput v-model="form.end_date" type="date" required :label="t('proDev.trainings.endDate')" :error="errors.end_date?.[0]" />
      </div>

      <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <BaseInput v-model="form.start_time" type="time" :label="t('proDev.trainings.startTime')" :error="errors.start_time?.[0]" />
        <BaseInput v-model="form.end_time" type="time" :label="t('proDev.trainings.endTime')" :error="errors.end_time?.[0]" />
        <BaseInput v-model="form.hours" type="number" :label="t('proDev.trainings.hours')" :error="errors.hours?.[0]" />
        <BaseInput v-model="form.cost" type="number" :label="t('proDev.trainings.cost')" :error="errors.cost?.[0]" />
      </div>

      <div>
        <BaseMultiSelect
          v-model="form.participant_ids"
          :options="participantOptions"
          :label="t('proDev.trainings.participants')"
          :placeholder="t('proDev.trainings.participantsPlaceholder')"
          :hint="form.status === 'planned' ? t('proDev.trainings.participantsHint') : undefined"
          :error="errors.participant_ids?.[0] ?? errors['participant_ids.0']?.[0]"
        />
        <div class="mt-2 flex flex-wrap items-center gap-3 text-sm">
          <label class="inline-flex items-center gap-2 text-neutral-700">
            <input v-model="teachersOnly" type="checkbox" class="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-200" />
            {{ t('proDev.trainings.teachersOnly') }}
          </label>
          <button type="button" class="font-medium text-secondary-700 hover:underline" @click="selectAllTeachers">{{ t('proDev.trainings.addAllTeachers') }}</button>
        </div>
      </div>

      <div>
        <label for="training-description" class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('proDev.trainings.description') }}</label>
        <textarea id="training-description" v-model="form.description" rows="4" :placeholder="t('proDev.trainings.descriptionPlaceholder')" :class="fieldClass" />
        <p v-if="errors.description?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.description[0] }}</p>
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</BaseButton>
      <BaseButton type="submit" form="training-form" :loading="saving">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
