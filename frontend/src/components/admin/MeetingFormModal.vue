<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseMultiSelect from '@/components/ui/BaseMultiSelect.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import type { StaffOption } from '@/services/inquiries'
import { meetingsService, type Meeting, type MeetingInput } from '@/services/meetings'
import { ApiRequestError } from '@/types/api'

/** Arrange a meeting, or edit one: when, where, who and the agenda. */
const props = defineProps<{ modelValue: boolean; meeting: Meeting | null; staff: StaffOption[] }>()
const emit = defineEmits<{ 'update:modelValue': [boolean]; saved: [Meeting] }>()

const { t } = useI18n()

function todayIso(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const form = reactive({
  title: '',
  meeting_date: '',
  start_time: '',
  end_time: '',
  location: '',
  organizer_staff_id: '',
  participant_ids: [] as string[],
  guests: '',
  agenda: '',
})
const errors = ref<Record<string, string[]>>({})
const error = ref<string | null>(null)
const saving = ref(false)

watch(
  () => props.modelValue,
  async (open) => {
    if (!open) return
    errors.value = {}
    error.value = null
    let m = props.meeting
    // The list rows carry only a count of participants; fetch the names.
    if (m && !m.participants) {
      try {
        m = await meetingsService.get(m.id)
      } catch {
        // Fall back to what the row has.
      }
    }
    Object.assign(form, {
      title: m?.title ?? '',
      meeting_date: m?.meeting_date ?? todayIso(),
      start_time: m?.start_time ?? '09:00',
      end_time: m?.end_time ?? '',
      location: m?.location ?? '',
      organizer_staff_id: m?.organizer_staff_id ? String(m.organizer_staff_id) : '',
      participant_ids: (m?.participants ?? []).map((p) => String(p.id)),
      guests: m?.guests ?? '',
      agenda: m?.agenda ?? '',
    })
  },
)

const organizerOptions = computed(() => [
  { value: '', label: '—' },
  ...props.staff.map((s) => ({ value: String(s.id), label: s.name, hint: s.employee_code ?? undefined })),
])
const participantOptions = computed(() => props.staff.map((s) => ({ value: String(s.id), label: s.employee_code ? `${s.name} (${s.employee_code})` : s.name })))

const fieldClass =
  'block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200'

async function save() {
  saving.value = true
  errors.value = {}
  error.value = null
  const input: MeetingInput = {
    title: form.title.trim(),
    meeting_date: form.meeting_date,
    start_time: form.start_time,
    end_time: form.end_time || null,
    location: form.location.trim() || null,
    organizer_staff_id: form.organizer_staff_id ? Number(form.organizer_staff_id) : null,
    participant_ids: form.participant_ids.map(Number),
    guests: form.guests.trim() || null,
    agenda: form.agenda.trim() || null,
  }
  try {
    const saved = props.meeting ? await meetingsService.update(props.meeting.id, input) : await meetingsService.create(input)
    emit('saved', saved)
    emit('update:modelValue', false)
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else error.value = e instanceof ApiRequestError ? e.message : t('frontOffice.meetings.saveFailed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    size="lg"
    :title="meeting ? t('frontOffice.meetings.editTitle') : t('frontOffice.meetings.addTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form id="meeting-form" class="space-y-4" novalidate @submit.prevent="save">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>

      <BaseInput v-model="form.title" required :label="t('frontOffice.meetings.title')" :placeholder="t('frontOffice.meetings.titlePlaceholder')" :error="errors.title?.[0]" />

      <div class="grid gap-4 sm:grid-cols-3">
        <BaseInput v-model="form.meeting_date" type="date" required :label="t('frontOffice.meetings.date')" :error="errors.meeting_date?.[0]" />
        <BaseInput v-model="form.start_time" type="time" required :label="t('frontOffice.meetings.startTime')" :error="errors.start_time?.[0]" />
        <BaseInput v-model="form.end_time" type="time" :label="t('frontOffice.meetings.endTime')" :error="errors.end_time?.[0]" />
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.location" :label="t('frontOffice.meetings.location')" :placeholder="t('frontOffice.meetings.locationPlaceholder')" :error="errors.location?.[0]" />
        <SearchableSelect v-model="form.organizer_staff_id" :options="organizerOptions" :label="t('frontOffice.meetings.organizer')" :error="errors.organizer_staff_id?.[0]" />
      </div>

      <BaseMultiSelect
        v-model="form.participant_ids"
        :options="participantOptions"
        :label="t('frontOffice.meetings.participants')"
        :placeholder="t('frontOffice.meetings.participantsPlaceholder')"
        :hint="t('frontOffice.meetings.participantsHint')"
        :error="errors.participant_ids?.[0] ?? errors['participant_ids.0']?.[0]"
      />

      <div>
        <label for="meeting-guests" class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('frontOffice.meetings.guests') }}</label>
        <textarea id="meeting-guests" v-model="form.guests" rows="2" :placeholder="t('frontOffice.meetings.guestsPlaceholder')" :class="fieldClass" />
        <p v-if="errors.guests?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.guests[0] }}</p>
      </div>

      <div>
        <label for="meeting-agenda" class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('frontOffice.meetings.agenda') }}</label>
        <textarea id="meeting-agenda" v-model="form.agenda" rows="5" :placeholder="t('frontOffice.meetings.agendaPlaceholder')" :class="fieldClass" />
        <p v-if="errors.agenda?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.agenda[0] }}</p>
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</BaseButton>
      <BaseButton type="submit" form="meeting-form" :loading="saving">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
