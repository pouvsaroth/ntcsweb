<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { meetingsService, type Meeting, type MeetingStatus } from '@/services/meetings'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate, formatTimeOfDay } from '@/utils/date'

/**
 * One meeting: when and where, who takes part, the agenda, and the meeting
 * note (written here during or after it). Mark it completed, cancel it, or
 * put it back to scheduled.
 */
const props = defineProps<{ modelValue: boolean; meetingId: number | null }>()
const emit = defineEmits<{ 'update:modelValue': [boolean]; changed: []; edit: [Meeting] }>()

const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const meeting = ref<Meeting | null>(null)
const loading = ref(false)
const loadError = ref<string | null>(null)
const minutes = ref('')
const savingNote = ref(false)
const noteSaved = ref(false)
const busy = ref(false)
const actionError = ref<string | null>(null)

const canUpdate = computed(() => auth.can('meetings.update'))
const noteChanged = computed(() => (meeting.value?.minutes ?? '') !== minutes.value)
const guests = computed(() => (meeting.value?.guests ?? '').split('\n').map((g) => g.trim()).filter(Boolean))

async function load() {
  if (!props.meetingId) return
  loading.value = true
  loadError.value = null
  try {
    meeting.value = await meetingsService.get(props.meetingId)
    minutes.value = meeting.value.minutes ?? ''
  } catch (e) {
    loadError.value = e instanceof ApiRequestError ? e.message : t('frontOffice.meetings.loadFailed')
  } finally {
    loading.value = false
  }
}

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    meeting.value = null
    actionError.value = null
    noteSaved.value = false
    void load()
  },
)

async function saveNote() {
  if (!meeting.value) return
  savingNote.value = true
  actionError.value = null
  try {
    meeting.value = await meetingsService.update(meeting.value.id, { minutes: minutes.value.trim() || null })
    minutes.value = meeting.value.minutes ?? ''
    noteSaved.value = true
    emit('changed')
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('frontOffice.meetings.saveFailed')
  } finally {
    savingNote.value = false
  }
}

async function setStatus(status: MeetingStatus) {
  if (!meeting.value) return
  if (status === 'cancelled' && !(await confirmDialog.confirm({ message: t('frontOffice.meetings.cancelConfirm', { title: meeting.value.title }), danger: true }))) return
  busy.value = true
  actionError.value = null
  try {
    // Keep an unsaved note when marking it done.
    const payload = noteChanged.value ? { status, minutes: minutes.value.trim() || null } : { status }
    meeting.value = await meetingsService.update(meeting.value.id, payload)
    minutes.value = meeting.value.minutes ?? ''
    emit('changed')
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('frontOffice.meetings.saveFailed')
  } finally {
    busy.value = false
  }
}

const statusVariant = (s: string) => (({ scheduled: 'primary', completed: 'success', cancelled: 'neutral' }) as const)[s as 'scheduled'] ?? 'neutral'
const timeRange = (m: Meeting) => (m.end_time ? `${formatTimeOfDay(m.start_time)} – ${formatTimeOfDay(m.end_time)}` : formatTimeOfDay(m.start_time))
</script>

<template>
  <BaseModal :model-value="modelValue" size="lg" :title="meeting?.title ?? t('frontOffice.meetings.detailTitle')" @update:model-value="emit('update:modelValue', $event)">
    <BaseAlert v-if="loadError" variant="danger">{{ loadError }}</BaseAlert>
    <p v-else-if="loading || !meeting" class="py-8 text-center text-sm text-neutral-500">{{ t('common.loading') }}</p>

    <div v-else class="space-y-5">
      <BaseAlert v-if="actionError" variant="danger">{{ actionError }}</BaseAlert>

      <div class="flex flex-wrap items-center gap-2">
        <BaseBadge :variant="statusVariant(meeting.status)">{{ t(`frontOffice.meetings.statuses.${meeting.status}`) }}</BaseBadge>
        <span class="text-sm text-neutral-700">{{ formatDate(meeting.meeting_date) }} · {{ timeRange(meeting) }}</span>
      </div>

      <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
        <div>
          <dt class="text-neutral-500">{{ t('frontOffice.meetings.location') }}</dt>
          <dd class="font-medium text-neutral-900">{{ meeting.location || '—' }}</dd>
        </div>
        <div>
          <dt class="text-neutral-500">{{ t('frontOffice.meetings.organizer') }}</dt>
          <dd class="font-medium text-neutral-900">{{ meeting.organizer?.name ?? '—' }}</dd>
        </div>
        <div class="sm:col-span-2">
          <dt class="mb-1 text-neutral-500">{{ t('frontOffice.meetings.participants') }} ({{ (meeting.participants?.length ?? 0) + guests.length }})</dt>
          <dd class="flex flex-wrap gap-1.5">
            <span v-if="!meeting.participants?.length && !guests.length" class="text-neutral-500">—</span>
            <span v-for="p in meeting.participants" :key="p.id" class="rounded-full bg-primary-50 px-2.5 py-0.5 text-xs font-medium text-primary-800">{{ p.name }}</span>
            <span v-for="g in guests" :key="g" class="rounded-full border border-neutral-300 px-2.5 py-0.5 text-xs text-neutral-700">
              {{ g }} · {{ t('frontOffice.meetings.guest') }}
            </span>
          </dd>
        </div>
      </dl>

      <div>
        <h3 class="mb-1.5 text-sm font-semibold text-neutral-900">{{ t('frontOffice.meetings.agenda') }}</h3>
        <p v-if="meeting.agenda" class="whitespace-pre-line rounded-lg bg-neutral-50 p-3 text-sm text-neutral-900">{{ meeting.agenda }}</p>
        <p v-else class="text-sm text-neutral-500">{{ t('frontOffice.meetings.noAgenda') }}</p>
      </div>

      <div>
        <label for="meeting-minutes" class="mb-1.5 block text-sm font-semibold text-neutral-900">{{ t('frontOffice.meetings.minutes') }}</label>
        <template v-if="canUpdate">
          <textarea
            id="meeting-minutes"
            v-model="minutes"
            rows="6"
            :placeholder="t('frontOffice.meetings.minutesPlaceholder')"
            class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
            @input="noteSaved = false"
          />
          <div class="mt-2 flex items-center justify-end gap-3">
            <span v-if="noteSaved && !noteChanged" class="text-xs text-success-600">{{ t('frontOffice.meetings.noteSaved') }}</span>
            <BaseButton size="sm" :loading="savingNote" :disabled="!noteChanged" @click="saveNote">{{ t('frontOffice.meetings.saveNote') }}</BaseButton>
          </div>
        </template>
        <p v-else-if="meeting.minutes" class="whitespace-pre-line text-sm text-neutral-900">{{ meeting.minutes }}</p>
        <p v-else class="text-sm text-neutral-500">{{ t('frontOffice.meetings.noMinutes') }}</p>
      </div>
    </div>

    <template #footer>
      <template v-if="meeting && canUpdate">
        <BaseButton v-if="meeting.status === 'scheduled'" variant="outline" :disabled="busy" @click="setStatus('cancelled')">{{ t('frontOffice.meetings.cancelMeeting') }}</BaseButton>
        <BaseButton v-if="meeting.status !== 'scheduled'" variant="outline" :disabled="busy" @click="setStatus('scheduled')">{{ t('frontOffice.meetings.reopen') }}</BaseButton>
        <BaseButton variant="outline" @click="emit('edit', meeting)">{{ t('common.edit') }}</BaseButton>
        <BaseButton v-if="meeting.status === 'scheduled'" :loading="busy" @click="setStatus('completed')">{{ t('frontOffice.meetings.markCompleted') }}</BaseButton>
      </template>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
    </template>
  </BaseModal>
</template>
