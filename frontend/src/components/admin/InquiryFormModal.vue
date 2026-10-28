<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { INQUIRY_SOURCES, INQUIRY_STATUSES, inquiriesService, type Inquiry, type InquiryInput, type StaffOption } from '@/services/inquiries'
import { ApiRequestError } from '@/types/api'

/** Record a new inquiry, or edit one. */
const props = defineProps<{ modelValue: boolean; inquiry: Inquiry | null; staff: StaffOption[] }>()
const emit = defineEmits<{ 'update:modelValue': [boolean]; saved: [Inquiry] }>()

const { t } = useI18n()

function todayIso(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const form = reactive({
  inquiry_date: '',
  name: '',
  gender: '',
  phone: '',
  email: '',
  interested_in: '',
  source: 'walk_in',
  status: 'new',
  next_follow_up_on: '',
  assigned_staff_id: '',
  note: '',
})
const errors = ref<Record<string, string[]>>({})
const error = ref<string | null>(null)
const saving = ref(false)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    const i = props.inquiry
    Object.assign(form, {
      inquiry_date: i?.inquiry_date ?? todayIso(),
      name: i?.name ?? '',
      gender: i?.gender ?? '',
      phone: i?.phone ?? '',
      email: i?.email ?? '',
      interested_in: i?.interested_in ?? '',
      source: i?.source ?? 'walk_in',
      status: i?.status ?? 'new',
      next_follow_up_on: i?.next_follow_up_on ?? '',
      assigned_staff_id: i?.assigned_staff_id ? String(i.assigned_staff_id) : '',
      note: i?.note ?? '',
    })
    errors.value = {}
    error.value = null
  },
)

const sourceOptions = computed(() => INQUIRY_SOURCES.map((s) => ({ value: s, label: t(`frontOffice.inquiries.sources.${s}`) })))
const statusOptions = computed(() => INQUIRY_STATUSES.map((s) => ({ value: s, label: t(`frontOffice.inquiries.statuses.${s}`) })))
const genderOptions = computed(() => [
  { value: '', label: '—' },
  { value: 'male', label: t('frontOffice.male') },
  { value: 'female', label: t('frontOffice.female') },
])
const staffOptions = computed(() => [
  { value: '', label: t('frontOffice.inquiries.notAssigned') },
  ...props.staff.map((s) => ({ value: String(s.id), label: s.name, hint: s.employee_code ?? undefined })),
])

async function save() {
  saving.value = true
  errors.value = {}
  error.value = null
  const input: InquiryInput = {
    inquiry_date: form.inquiry_date,
    name: form.name.trim(),
    gender: (form.gender || null) as InquiryInput['gender'],
    phone: form.phone.trim(),
    email: form.email.trim() || null,
    interested_in: form.interested_in.trim() || null,
    source: form.source as InquiryInput['source'],
    next_follow_up_on: form.next_follow_up_on || null,
    assigned_staff_id: form.assigned_staff_id ? Number(form.assigned_staff_id) : null,
    note: form.note.trim() || null,
  }
  try {
    const saved = props.inquiry
      ? await inquiriesService.update(props.inquiry.id, { ...input, status: form.status as InquiryInput['status'] })
      : await inquiriesService.create(input)
    emit('saved', saved)
    emit('update:modelValue', false)
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else error.value = e instanceof ApiRequestError ? e.message : t('frontOffice.inquiries.saveFailed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    size="lg"
    :title="inquiry ? t('frontOffice.inquiries.editTitle') : t('frontOffice.inquiries.addTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form id="inquiry-form" class="space-y-4" novalidate @submit.prevent="save">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>

      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.name" required :label="t('frontOffice.inquiries.name')" :error="errors.name?.[0]" />
        <BaseInput v-model="form.phone" required type="tel" :label="t('common.phone')" :error="errors.phone?.[0]" />
        <BaseSelect v-model="form.gender" :options="genderOptions" :label="t('frontOffice.gender')" :error="errors.gender?.[0]" />
        <BaseInput v-model="form.email" type="email" :label="t('frontOffice.email')" :error="errors.email?.[0]" />
        <div class="sm:col-span-2">
          <BaseInput
            v-model="form.interested_in"
            :label="t('frontOffice.inquiries.interestedIn')"
            :placeholder="t('frontOffice.inquiries.interestedInPlaceholder')"
            :error="errors.interested_in?.[0]"
          />
        </div>
        <BaseInput v-model="form.inquiry_date" type="date" required :label="t('frontOffice.inquiries.date')" :error="errors.inquiry_date?.[0]" />
        <BaseSelect v-model="form.source" :options="sourceOptions" required :label="t('frontOffice.inquiries.source')" :error="errors.source?.[0]" />
        <BaseInput v-model="form.next_follow_up_on" type="date" :label="t('frontOffice.inquiries.nextFollowUp')" :error="errors.next_follow_up_on?.[0]" />
        <SearchableSelect v-model="form.assigned_staff_id" :options="staffOptions" :label="t('frontOffice.inquiries.assignedTo')" :error="errors.assigned_staff_id?.[0]" />
        <BaseSelect v-if="inquiry" v-model="form.status" :options="statusOptions" :label="t('frontOffice.inquiries.status')" :error="errors.status?.[0]" />
        <div class="sm:col-span-2">
          <label for="inquiry-note" class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('frontOffice.inquiries.note') }}</label>
          <textarea
            id="inquiry-note"
            v-model="form.note"
            rows="3"
            class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
          />
          <p v-if="errors.note?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.note[0] }}</p>
        </div>
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</BaseButton>
      <BaseButton type="submit" form="inquiry-form" :loading="saving">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
