<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { departmentsService, type Department } from '@/services/departments'
import { JOB_POSITION_STATUSES, jobPositionsService, type JobPosition, type JobPositionStatus, type SalaryCurrency } from '@/services/jobPositions'
import { EMPLOYMENT_TYPES, type EmploymentType, type ManpowerRequest } from '@/services/manpowerRequests'
import { organizationUnitsService, type OrganizationUnit } from '@/services/organizationUnits'
import { positionsService, type Position } from '@/services/positions'
import { ApiRequestError } from '@/types/api'
import { optionalOptions } from '@/utils/organizationOptions'

/**
 * Open or edit a job position. Opened from an approved manpower request
 * (`fromRequest`), its job, department, headcount, type and requirements
 * are filled in from it.
 */
const props = defineProps<{
  modelValue: boolean
  job?: JobPosition | null
  fromRequest?: ManpowerRequest | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.job != null)

const form = reactive({
  manpower_request_id: null as number | null,
  title: '',
  department_id: null as number | null,
  position_id: null as number | null,
  branch_id: null as number | null,
  headcount: '1',
  employment_type: 'full_time' as EmploymentType,
  salary_min: '',
  salary_max: '',
  salary_currency: 'USD' as SalaryCurrency,
  description: '',
  requirements: '',
  status: 'open' as JobPositionStatus,
  opened_on: '',
  closes_on: '',
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const departments = ref<Department[]>([])
const positions = ref<Position[]>([])
const branches = ref<OrganizationUnit[]>([])
const none = computed(() => t('admin.organization.none'))
const departmentOptions = computed(() => optionalOptions(departments.value, none.value))
const positionOptions = computed(() => optionalOptions(positions.value, none.value))
const branchOptions = computed(() => optionalOptions(branches.value, none.value))
const employmentTypeOptions = computed(() => EMPLOYMENT_TYPES.map((type) => ({ value: type, label: t(`admin.recruitment.employmentTypes.${type}`) })))
const statusOptions = computed(() => JOB_POSITION_STATUSES.map((status) => ({ value: status, label: t(`admin.recruitment.jobStatuses.${status}`) })))
const currencyOptions = [
  { value: 'USD', label: 'USD' },
  { value: 'KHR', label: 'KHR' },
]

onMounted(async () => {
  const [departmentRows, positionRows, branchRows] = await Promise.all([
    departmentsService.listAll().catch(() => []),
    positionsService.listAll().catch(() => []),
    organizationUnitsService('branches').listAll().catch(() => []),
  ])
  departments.value = departmentRows
  positions.value = positionRows
  branches.value = branchRows
})

function amount(value: number | null | undefined): string {
  return value === null || value === undefined ? '' : String(value)
}

watch(
  () => [props.modelValue, props.job, props.fromRequest] as const,
  ([open]) => {
    if (!open) return

    const job = props.job
    const request = props.fromRequest
    form.manpower_request_id = job?.manpower_request_id ?? request?.id ?? null
    form.title = job?.title ?? request?.job_title ?? ''
    form.department_id = job?.department_id ?? request?.department_id ?? null
    form.position_id = job?.position_id ?? request?.position_id ?? null
    form.branch_id = job?.branch_id ?? null
    form.headcount = String(job?.headcount ?? request?.headcount ?? 1)
    form.employment_type = job?.employment_type ?? request?.employment_type ?? 'full_time'
    form.salary_min = amount(job?.salary_min)
    form.salary_max = amount(job?.salary_max)
    form.salary_currency = job?.salary_currency ?? 'USD'
    form.description = job?.description ?? ''
    form.requirements = job?.requirements ?? request?.requirements ?? ''
    form.status = job?.status ?? 'open'
    form.opened_on = job?.opened_on ?? ''
    form.closes_on = job?.closes_on ?? request?.needed_by ?? ''
    errors.value = {}
    generalError.value = null
  },
  { immediate: true },
)

function toNumber(value: string): number | null {
  return value.trim() === '' ? null : Number(value)
}

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  const input = {
    ...form,
    headcount: Number(form.headcount),
    salary_min: toNumber(form.salary_min),
    salary_max: toNumber(form.salary_max),
    opened_on: form.opened_on || null,
    closes_on: form.closes_on || null,
  }

  try {
    if (isEditing.value) {
      await jobPositionsService.update(props.job!.id, input)
    } else {
      await jobPositionsService.create(input)
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.recruitment.positions.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}

const textareaClass =
  'block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200'
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.recruitment.positions.edit') : t('admin.recruitment.positions.add')"
    size="lg"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>
      <BaseAlert v-if="fromRequest && !isEditing" variant="info">
        {{ t('admin.recruitment.positions.fromRequest', { reference: fromRequest.reference }) }}
      </BaseAlert>

      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.title" required class="sm:col-span-2" :label="t('admin.recruitment.positions.title')" :error="errors.title?.[0]" />
        <BaseSelect
          :model-value="form.position_id ? String(form.position_id) : ''"
          :options="positionOptions"
          :label="t('admin.recruitment.manpower.position')"
          :error="errors.position_id?.[0]"
          @update:model-value="(value: string) => (form.position_id = value ? Number(value) : null)"
        />
        <BaseSelect
          :model-value="form.department_id ? String(form.department_id) : ''"
          :options="departmentOptions"
          :label="t('admin.recruitment.manpower.department')"
          :error="errors.department_id?.[0]"
          @update:model-value="(value: string) => (form.department_id = value ? Number(value) : null)"
        />
        <BaseSelect
          :model-value="form.branch_id ? String(form.branch_id) : ''"
          :options="branchOptions"
          :label="t('admin.recruitment.positions.location')"
          :error="errors.branch_id?.[0]"
          @update:model-value="(value: string) => (form.branch_id = value ? Number(value) : null)"
        />
        <BaseInput v-model="form.headcount" type="number" min="1" required :label="t('admin.recruitment.manpower.headcount')" :error="errors.headcount?.[0]" />
        <BaseSelect v-model="form.employment_type" :options="employmentTypeOptions" :label="t('admin.recruitment.manpower.employmentType')" />
        <BaseSelect v-model="form.status" :options="statusOptions" :label="t('admin.recruitment.positions.status')" />
      </div>

      <div class="grid gap-4 sm:grid-cols-3">
        <BaseInput v-model="form.salary_min" type="number" min="0" :label="t('admin.recruitment.positions.salaryMin')" :error="errors.salary_min?.[0]" />
        <BaseInput v-model="form.salary_max" type="number" min="0" :label="t('admin.recruitment.positions.salaryMax')" :error="errors.salary_max?.[0]" />
        <BaseSelect v-model="form.salary_currency" :options="currencyOptions" :label="t('admin.recruitment.positions.currency')" />
        <BaseInput v-model="form.opened_on" type="date" :label="t('admin.recruitment.positions.openedOn')" :error="errors.opened_on?.[0]" />
        <BaseInput v-model="form.closes_on" type="date" :label="t('admin.recruitment.positions.closesOn')" :error="errors.closes_on?.[0]" />
      </div>

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.recruitment.positions.description') }}</label>
        <textarea v-model="form.description" rows="4" :class="textareaClass" :placeholder="t('admin.recruitment.positions.descriptionHint')" />
      </div>
      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.recruitment.manpower.requirements') }}</label>
        <textarea v-model="form.requirements" rows="4" :class="textareaClass" :placeholder="t('admin.recruitment.manpower.requirementsHint')" />
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
