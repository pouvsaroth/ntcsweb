<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { departmentsService, type Department } from '@/services/departments'
import type { SalaryCurrency } from '@/services/jobPositions'
import { EMPLOYMENT_TYPES, type EmploymentType } from '@/services/manpowerRequests'
import { offerLettersService, type OfferLetter } from '@/services/offerLetters'
import { ApiRequestError } from '@/types/api'
import { optionalOptions } from '@/utils/organizationOptions'

/**
 * Make (or edit) an offer. A new one is for `forApplicant` — Candidate
 * selection's "Make offer" — and starts from that applicant's job.
 */
const props = defineProps<{
  modelValue: boolean
  offer?: OfferLetter | null
  forApplicant?: { id: number; name: string; jobTitle: string | null; expectedSalary: number | null; departmentId?: number | null; employmentType?: EmploymentType } | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [offer: OfferLetter] }>()

const { t } = useI18n()

const isEditing = computed(() => props.offer != null)

function inDays(days: number): string {
  const d = new Date()
  d.setDate(d.getDate() + days)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const form = reactive({
  position_title: '',
  department_id: null as number | null,
  employment_type: 'full_time' as EmploymentType,
  salary: '',
  salary_currency: 'USD' as SalaryCurrency,
  start_date: '',
  probation_months: '',
  expires_on: '',
  benefits: '',
  terms: '',
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const departments = ref<Department[]>([])
const departmentOptions = computed(() => optionalOptions(departments.value, t('admin.organization.none')))
const employmentTypeOptions = computed(() => EMPLOYMENT_TYPES.map((type) => ({ value: type, label: t(`admin.recruitment.employmentTypes.${type}`) })))
const currencyOptions = [
  { value: 'USD', label: 'USD' },
  { value: 'KHR', label: 'KHR' },
]

onMounted(async () => {
  departments.value = await departmentsService.listAll().catch(() => [])
})

watch(
  () => [props.modelValue, props.offer, props.forApplicant] as const,
  ([open]) => {
    if (!open) return

    const o = props.offer
    const a = props.forApplicant
    form.position_title = o?.position_title ?? a?.jobTitle ?? ''
    form.department_id = o?.department_id ?? a?.departmentId ?? null
    form.employment_type = o?.employment_type ?? a?.employmentType ?? 'full_time'
    form.salary = o ? String(o.salary) : a?.expectedSalary != null ? String(a.expectedSalary) : ''
    form.salary_currency = o?.salary_currency ?? 'USD'
    form.start_date = o?.start_date ?? inDays(30)
    form.probation_months = o?.probation_months != null ? String(o.probation_months) : '3'
    form.expires_on = o?.expires_on ?? inDays(7)
    form.benefits = o?.benefits ?? ''
    form.terms = o?.terms ?? ''
    errors.value = {}
    generalError.value = null
  },
  { immediate: true },
)

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  const input = {
    position_title: form.position_title,
    department_id: form.department_id,
    employment_type: form.employment_type,
    salary: Number(form.salary),
    salary_currency: form.salary_currency,
    start_date: form.start_date,
    probation_months: form.probation_months === '' ? null : Number(form.probation_months),
    expires_on: form.expires_on || null,
    benefits: form.benefits.trim() || null,
    terms: form.terms.trim() || null,
  }

  try {
    const saved = isEditing.value
      ? await offerLettersService.update(props.offer!.id, input)
      : await offerLettersService.create({ ...input, applicant_id: props.forApplicant!.id })
    emit('saved', saved)
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) errors.value = error.errors
    else generalError.value = error instanceof ApiRequestError ? error.message : t('admin.recruitment.offers.saveFailed')
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
    :title="isEditing ? t('admin.recruitment.offers.edit') : t('admin.recruitment.offers.add')"
    size="lg"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>
      <BaseAlert v-if="errors.applicant_id?.[0]" variant="danger">{{ errors.applicant_id[0] }}</BaseAlert>

      <p class="rounded-lg bg-neutral-50 px-3 py-2 text-sm font-medium text-neutral-900">
        {{ offer?.applicant?.name ?? forApplicant?.name }}
      </p>

      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.position_title" required class="sm:col-span-2" :label="t('admin.recruitment.offers.position')" :error="errors.position_title?.[0]" />
        <BaseSelect
          :model-value="form.department_id ? String(form.department_id) : ''"
          :options="departmentOptions"
          :label="t('admin.recruitment.manpower.department')"
          @update:model-value="(value: string) => (form.department_id = value ? Number(value) : null)"
        />
        <BaseSelect v-model="form.employment_type" :options="employmentTypeOptions" :label="t('admin.recruitment.manpower.employmentType')" />
      </div>

      <div class="grid gap-4 sm:grid-cols-3">
        <BaseInput v-model="form.salary" type="number" min="0" required :label="t('admin.recruitment.offers.salaryPerMonth')" :error="errors.salary?.[0]" />
        <BaseSelect v-model="form.salary_currency" :options="currencyOptions" :label="t('admin.recruitment.positions.currency')" />
        <BaseInput v-model="form.probation_months" type="number" min="0" max="24" :label="t('admin.recruitment.offers.probationLabel')" :error="errors.probation_months?.[0]" />
        <BaseInput v-model="form.start_date" type="date" required :label="t('admin.recruitment.offers.startDate')" :error="errors.start_date?.[0]" />
        <BaseInput v-model="form.expires_on" type="date" :label="t('admin.recruitment.offers.expiresOn')" :error="errors.expires_on?.[0]" />
      </div>

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.recruitment.offers.benefits') }}</label>
        <textarea v-model="form.benefits" rows="2" :class="textareaClass" :placeholder="t('admin.recruitment.offers.benefitsHint')" />
      </div>
      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.recruitment.offers.terms') }}</label>
        <textarea v-model="form.terms" rows="4" :class="textareaClass" :placeholder="t('admin.recruitment.offers.termsHint')" />
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
