<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { frontDeskService, type Appointment, type AppointmentInput } from '@/services/frontDesk'
import type { StaffOption } from '@/services/inquiries'
import { ApiRequestError } from '@/types/api'

/** Book an appointment, or edit one. */
const props = defineProps<{ modelValue: boolean; appointment: Appointment | null; staff: StaffOption[] }>()
const emit = defineEmits<{ 'update:modelValue': [boolean]; saved: [Appointment] }>()

const { t } = useI18n()

function todayIso(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const form = reactive({
  visitor_name: '',
  phone: '',
  email: '',
  organization: '',
  purpose: '',
  appointment_date: '',
  start_time: '',
  end_time: '',
  host_staff_id: '',
  note: '',
})
const errors = ref<Record<string, string[]>>({})
const error = ref<string | null>(null)
const saving = ref(false)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    const a = props.appointment
    Object.assign(form, {
      visitor_name: a?.visitor_name ?? '',
      phone: a?.phone ?? '',
      email: a?.email ?? '',
      organization: a?.organization ?? '',
      purpose: a?.purpose ?? '',
      appointment_date: a?.appointment_date ?? todayIso(),
      start_time: a?.start_time ?? '09:00',
      end_time: a?.end_time ?? '',
      host_staff_id: a?.host_staff_id ? String(a.host_staff_id) : '',
      note: a?.note ?? '',
    })
    errors.value = {}
    error.value = null
  },
)

const hostOptions = computed(() => [
  { value: '', label: '—' },
  ...props.staff.map((s) => ({ value: String(s.id), label: s.name, hint: s.employee_code ?? undefined })),
])

async function save() {
  saving.value = true
  errors.value = {}
  error.value = null
  const input: AppointmentInput = {
    visitor_name: form.visitor_name.trim(),
    phone: form.phone.trim() || null,
    email: form.email.trim() || null,
    organization: form.organization.trim() || null,
    purpose: form.purpose.trim(),
    appointment_date: form.appointment_date,
    start_time: form.start_time,
    end_time: form.end_time || null,
    host_staff_id: form.host_staff_id ? Number(form.host_staff_id) : null,
    note: form.note.trim() || null,
  }
  try {
    const saved = props.appointment ? await frontDeskService.updateAppointment(props.appointment.id, input) : await frontDeskService.createAppointment(input)
    emit('saved', saved)
    emit('update:modelValue', false)
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else error.value = e instanceof ApiRequestError ? e.message : t('frontOffice.appointments.saveFailed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    size="lg"
    :title="appointment ? t('frontOffice.appointments.editTitle') : t('frontOffice.appointments.addTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form id="appointment-form" class="space-y-4" novalidate @submit.prevent="save">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>

      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.visitor_name" required :label="t('frontOffice.appointments.visitorName')" :error="errors.visitor_name?.[0]" />
        <BaseInput v-model="form.phone" type="tel" :label="t('common.phone')" :error="errors.phone?.[0]" />
        <BaseInput v-model="form.organization" :label="t('frontOffice.appointments.organization')" :placeholder="t('frontOffice.appointments.organizationPlaceholder')" :error="errors.organization?.[0]" />
        <BaseInput v-model="form.email" type="email" :label="t('frontOffice.email')" :error="errors.email?.[0]" />
        <div class="sm:col-span-2">
          <BaseInput v-model="form.purpose" required :label="t('frontOffice.appointments.purpose')" :placeholder="t('frontOffice.appointments.purposePlaceholder')" :error="errors.purpose?.[0]" />
        </div>
      </div>

      <div class="grid gap-4 sm:grid-cols-3">
        <BaseInput v-model="form.appointment_date" type="date" required :label="t('frontOffice.meetings.date')" :error="errors.appointment_date?.[0]" />
        <BaseInput v-model="form.start_time" type="time" required :label="t('frontOffice.meetings.startTime')" :error="errors.start_time?.[0]" />
        <BaseInput v-model="form.end_time" type="time" :label="t('frontOffice.meetings.endTime')" :error="errors.end_time?.[0]" />
      </div>

      <SearchableSelect
        v-model="form.host_staff_id"
        :options="hostOptions"
        :label="t('frontOffice.appointments.host')"
        :hint="t('frontOffice.appointments.hostHint')"
        :error="errors.host_staff_id?.[0]"
      />

      <div>
        <label for="appointment-note" class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('frontOffice.inquiries.note') }}</label>
        <textarea
          id="appointment-note"
          v-model="form.note"
          rows="2"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</BaseButton>
      <BaseButton type="submit" form="appointment-form" :loading="saving">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
