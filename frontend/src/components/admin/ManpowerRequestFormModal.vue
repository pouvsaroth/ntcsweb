<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { departmentsService, type Department } from '@/services/departments'
import { EMPLOYMENT_TYPES, manpowerRequestsService, type EmploymentType, type ManpowerRequest } from '@/services/manpowerRequests'
import { positionsService, type Position } from '@/services/positions'
import { ApiRequestError } from '@/types/api'
import { optionalOptions } from '@/utils/organizationOptions'

/**
 * Create or (while still pending) edit a manpower request. Saving a new one
 * submits it straight into E-Approvals > Approvals.
 */
const props = defineProps<{
  modelValue: boolean
  request?: ManpowerRequest | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.request != null)

const form = reactive({
  department_id: null as number | null,
  position_id: null as number | null,
  job_title: '',
  headcount: '1',
  employment_type: 'full_time' as EmploymentType,
  needed_by: '',
  reason: '',
  requirements: '',
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

// Both lists are optional pickers — an account that can't read one still gets a working form.
const departments = ref<Department[]>([])
const positions = ref<Position[]>([])
const departmentOptions = computed(() => optionalOptions(departments.value, t('admin.organization.none')))
const positionOptions = computed(() => optionalOptions(positions.value, t('admin.organization.none')))
const employmentTypeOptions = computed(() => EMPLOYMENT_TYPES.map((type) => ({ value: type, label: t(`admin.recruitment.employmentTypes.${type}`) })))

onMounted(async () => {
  const [departmentRows, positionRows] = await Promise.all([
    departmentsService.listAll().catch(() => []),
    positionsService.listAll().catch(() => []),
  ])
  departments.value = departmentRows
  positions.value = positionRows
})

watch(
  () => [props.modelValue, props.request] as const,
  ([open]) => {
    if (!open) return

    form.department_id = props.request?.department_id ?? null
    form.position_id = props.request?.position_id ?? null
    form.job_title = props.request?.job_title ?? ''
    form.headcount = String(props.request?.headcount ?? 1)
    form.employment_type = props.request?.employment_type ?? 'full_time'
    form.needed_by = props.request?.needed_by ?? ''
    form.reason = props.request?.reason ?? ''
    form.requirements = props.request?.requirements ?? ''
    errors.value = {}
    generalError.value = null
  },
  { immediate: true },
)

/** Picking a position fills an empty job title with its name — still editable. */
function onPositionChange(value: string) {
  form.position_id = value ? Number(value) : null
  const position = positions.value.find((p) => p.id === form.position_id)
  if (position && form.job_title.trim() === '') form.job_title = position.name
}

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  const input = { ...form, headcount: Number(form.headcount), needed_by: form.needed_by || null }

  try {
    if (isEditing.value) {
      await manpowerRequestsService.update(props.request!.id, input)
    } else {
      await manpowerRequestsService.create(input)
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.recruitment.manpower.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.recruitment.manpower.edit') : t('admin.recruitment.manpower.add')"
    size="lg"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>
      <p v-if="!isEditing" class="text-sm text-neutral-500">{{ t('admin.recruitment.manpower.submitHint') }}</p>

      <div class="grid gap-4 sm:grid-cols-2">
        <BaseSelect
          :model-value="form.position_id ? String(form.position_id) : ''"
          :options="positionOptions"
          :label="t('admin.recruitment.manpower.position')"
          :error="errors.position_id?.[0]"
          @update:model-value="onPositionChange"
        />
        <BaseInput v-model="form.job_title" required :label="t('admin.recruitment.manpower.jobTitle')" :error="errors.job_title?.[0]" />
        <BaseSelect
          :model-value="form.department_id ? String(form.department_id) : ''"
          :options="departmentOptions"
          :label="t('admin.recruitment.manpower.department')"
          :error="errors.department_id?.[0]"
          @update:model-value="(value: string) => (form.department_id = value ? Number(value) : null)"
        />
        <BaseInput v-model="form.headcount" type="number" min="1" required :label="t('admin.recruitment.manpower.headcount')" :error="errors.headcount?.[0]" />
        <BaseSelect
          v-model="form.employment_type"
          :options="employmentTypeOptions"
          :label="t('admin.recruitment.manpower.employmentType')"
          :error="errors.employment_type?.[0]"
        />
        <BaseInput v-model="form.needed_by" type="date" :label="t('admin.recruitment.manpower.neededBy')" :error="errors.needed_by?.[0]" />
      </div>

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">
          {{ t('admin.recruitment.manpower.reason') }} <span class="text-danger-600">*</span>
        </label>
        <textarea
          v-model="form.reason"
          rows="3"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
        <p v-if="errors.reason?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.reason[0] }}</p>
      </div>

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.recruitment.manpower.requirements') }}</label>
        <textarea
          v-model="form.requirements"
          rows="3"
          :placeholder="t('admin.recruitment.manpower.requirementsHint')"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ isEditing ? t('common.save') : t('admin.recruitment.manpower.submit') }}</BaseButton>
    </template>
  </BaseModal>
</template>
