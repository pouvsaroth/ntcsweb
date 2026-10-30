<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { COACHING_TYPES, coachingService, type Coaching, type CoachingInput, type CoachingType, type PdStaffOption } from '@/services/professionalDevelopment'
import { ApiRequestError } from '@/types/api'

/**
 * Pair a coach or mentor with a staff member around a goal, or edit the
 * pairing. Opened from an observation, it starts with that teacher and its
 * next steps as the goal. Both people are notified.
 */
const props = defineProps<{ modelValue: boolean; coaching: Coaching | null; staff: PdStaffOption[]; preset?: Partial<CoachingInput> | null }>()
const emit = defineEmits<{ 'update:modelValue': [boolean]; saved: [Coaching] }>()

const { t } = useI18n()

function todayIso(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const form = reactive({
  type: 'coaching' as CoachingType,
  coach_staff_id: '',
  staff_id: '',
  goal: '',
  description: '',
  start_date: '',
  end_date: '',
})
const observationId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})
const error = ref<string | null>(null)
const saving = ref(false)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    errors.value = {}
    error.value = null
    const c = props.coaching
    const p = props.preset ?? {}
    Object.assign(form, {
      type: c?.type ?? p.type ?? 'coaching',
      coach_staff_id: String(c?.coach_staff_id ?? p.coach_staff_id ?? ''),
      staff_id: String(c?.staff_id ?? p.staff_id ?? ''),
      goal: c?.goal ?? p.goal ?? '',
      description: c?.description ?? p.description ?? '',
      start_date: c?.start_date ?? p.start_date ?? todayIso(),
      end_date: c?.end_date ?? p.end_date ?? '',
    })
    observationId.value = c?.classroom_observation_id ?? p.classroom_observation_id ?? null
  },
)

const staffOptions = computed(() => [{ value: '', label: '—' }, ...props.staff.map((s) => ({ value: String(s.id), label: s.name, hint: s.employee_code ?? undefined }))])
const typeOptions = computed(() => COACHING_TYPES.map((v) => ({ value: v, label: t(`proDev.coachingTypes.${v}`) })))

const fieldClass =
  'block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200'

async function save() {
  saving.value = true
  errors.value = {}
  error.value = null
  const input: CoachingInput = {
    type: form.type,
    coach_staff_id: Number(form.coach_staff_id),
    staff_id: Number(form.staff_id),
    classroom_observation_id: observationId.value,
    goal: form.goal.trim(),
    description: form.description.trim() || null,
    start_date: form.start_date,
    end_date: form.end_date || null,
  }
  try {
    const saved = props.coaching ? await coachingService.update(props.coaching.id, input) : await coachingService.create(input)
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
  <BaseModal :model-value="modelValue" size="lg" :title="coaching ? t('proDev.coaching.editTitle') : t('proDev.coaching.addTitle')" @update:model-value="emit('update:modelValue', $event)">
    <form id="coaching-form" class="space-y-4" novalidate @submit.prevent="save">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>
      <BaseAlert v-if="observationId && !coaching" variant="info">{{ t('proDev.coaching.fromObservation') }}</BaseAlert>

      <BaseSelect v-model="form.type" :options="typeOptions" :label="t('proDev.coaching.type')" />
      <div class="grid gap-4 sm:grid-cols-2">
        <SearchableSelect v-model="form.coach_staff_id" required :options="staffOptions" :label="t('proDev.coaching.coach')" :error="errors.coach_staff_id?.[0]" />
        <SearchableSelect v-model="form.staff_id" required :options="staffOptions" :label="t('proDev.coaching.coachee')" :error="errors.staff_id?.[0]" />
      </div>
      <BaseInput v-model="form.goal" required :label="t('proDev.coaching.goal')" :placeholder="t('proDev.coaching.goalPlaceholder')" :error="errors.goal?.[0]" />
      <div>
        <label for="coaching-description" class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('proDev.coaching.description') }}</label>
        <textarea id="coaching-description" v-model="form.description" rows="3" :placeholder="t('proDev.coaching.descriptionPlaceholder')" :class="fieldClass" />
      </div>
      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.start_date" type="date" required :label="t('proDev.coaching.startDate')" :error="errors.start_date?.[0]" />
        <BaseInput v-model="form.end_date" type="date" :label="t('proDev.coaching.endDate')" :error="errors.end_date?.[0]" />
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</BaseButton>
      <BaseButton type="submit" form="coaching-form" :loading="saving">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
