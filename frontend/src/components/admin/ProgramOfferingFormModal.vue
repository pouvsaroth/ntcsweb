<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { academicProgramsService, type AcademicProgram } from '@/services/academicPrograms'
import { academicYearsService, type AcademicYear } from '@/services/academicYears'
import { programOfferingsService, type ProgramOffering } from '@/services/programOfferings'
import { studyModesService, type StudyMode } from '@/services/studyModes'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  offering?: ProgramOffering | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.offering != null)

const form = reactive({
  academic_program_id: null as number | null,
  study_mode_id: null as number | null,
  academic_year_id: null as number | null,
  name: '',
  status: 'active' as 'active' | 'closed',
})

const programs = ref<AcademicProgram[]>([])
const studyModes = ref<StudyMode[]>([])
const academicYears = ref<AcademicYear[]>([])
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const programOptions = computed(() => programs.value.map((p) => ({ value: String(p.id), label: `${p.code} — ${p.name}` })))
const studyModeOptions = computed(() => studyModes.value.map((m) => ({ value: String(m.id), label: m.name })))
const academicYearOptions = computed(() => [
  { value: '', label: t('admin.programOfferings.noAcademicYear') },
  ...academicYears.value.map((y) => ({ value: String(y.id), label: y.name })),
])
const statusOptions = computed(() => [
  { value: 'active', label: t('admin.programOfferings.statusActive') },
  { value: 'closed', label: t('admin.programOfferings.statusClosed') },
])

onMounted(async () => {
  ;[programs.value, studyModes.value, academicYears.value] = await Promise.all([
    academicProgramsService.listAll(),
    studyModesService.listAll(),
    academicYearsService.listAll(),
  ])
})

watch(
  () => [props.modelValue, props.offering] as const,
  ([open]) => {
    if (!open) return

    form.academic_program_id = props.offering?.academic_program_id ?? null
    form.study_mode_id = props.offering?.study_mode_id ?? null
    form.academic_year_id = props.offering?.academic_year_id ?? null
    form.name = props.offering?.name ?? ''
    form.status = props.offering?.status ?? 'active'
    errors.value = {}
    generalError.value = null
  },
  { immediate: true },
)

async function submit() {
  if (form.academic_program_id === null || form.study_mode_id === null) return

  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    const input = { ...form, academic_program_id: form.academic_program_id, study_mode_id: form.study_mode_id }

    if (isEditing.value) {
      await programOfferingsService.update(props.offering!.id, input)
    } else {
      await programOfferingsService.create(input)
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.programOfferings.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.programOfferings.editTitle') : t('admin.programOfferings.createTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseSelect
        :model-value="form.academic_program_id !== null ? String(form.academic_program_id) : ''"
        :options="programOptions"
        required
        :placeholder="t('admin.programOfferings.selectProgram')"
        :label="t('admin.programOfferings.program')"
        :error="errors.academic_program_id?.[0]"
        @update:model-value="form.academic_program_id = $event ? Number($event) : null"
      />

      <BaseSelect
        :model-value="form.study_mode_id !== null ? String(form.study_mode_id) : ''"
        :options="studyModeOptions"
        required
        :placeholder="t('admin.programOfferings.selectStudyMode')"
        :label="t('admin.programOfferings.studyMode')"
        :error="errors.study_mode_id?.[0]"
        @update:model-value="form.study_mode_id = $event ? Number($event) : null"
      />

      <div class="grid grid-cols-2 gap-4">
        <BaseSelect
          :model-value="form.academic_year_id !== null ? String(form.academic_year_id) : ''"
          :options="academicYearOptions"
          :label="t('admin.programOfferings.academicYear')"
          :error="errors.academic_year_id?.[0]"
          @update:model-value="form.academic_year_id = $event ? Number($event) : null"
        />
        <BaseSelect v-model="form.status" :options="statusOptions" :label="t('admin.programOfferings.status')" />
      </div>

      <BaseInput v-model="form.name" :label="t('admin.programOfferings.name')" :hint="t('admin.programOfferings.nameHint')" :error="errors.name?.[0]" />
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" :disabled="form.academic_program_id === null || form.study_mode_id === null" @click="submit">
        {{ t('common.save') }}
      </BaseButton>
    </template>
  </BaseModal>
</template>
