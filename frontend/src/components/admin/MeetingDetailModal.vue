<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { MEETING_ATTENDANCE, meetingsService, type Meeting, type MeetingAttendance, type MeetingStatus } from '@/services/meetings'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate, formatTimeOfDay } from '@/utils/date'

/**
 * One meeting: when and where, who takes part, the agenda, and the meeting
 * note (written here during or after it). Mark it completed, cancel it, or
 * put it back to scheduled. Mark who came (staff participants and guests).
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

/** Attendance being edited: `s:<staff id>` or `g:<guest name>` → mark (or null). */
const marks = ref<Record<string, MeetingAttendance | null>>({})
const savingAttendance = ref(false)
const attendanceSaved = ref(false)

type Person = { key: string; name: string; guest: boolean; saved: MeetingAttendance | null }
const people = computed<Person[]>(() => {
  const m = meeting.value
  if (!m) return []
  return [
    ...(m.participants ?? []).map((p) => ({ key: `s:${p.id}`, name: p.name, guest: false, saved: p.attendance ?? null })),
    ...guests.value.map((g) => ({ key: `g:${g}`, name: g, guest: true, saved: m.guest_attendance?.[g] ?? null })),
  ]
})
const canMarkAttendance = computed(() => canUpdate.value && meeting.value?.status !== 'cancelled' && people.value.length > 0)
const attendanceChanged = computed(() => people.value.some((p) => (marks.value[p.key] ?? null) !== p.saved))
const attendanceCounts = computed(() => {
  const counts = { present: 0, late: 0, absent: 0, excused: 0, none: 0 }
  for (const p of people.value) counts[marks.value[p.key] ?? 'none']++
  return counts
})

function resetMarks() {
  marks.value = Object.fromEntries(people.value.map((p) => [p.key, p.saved]))
}

/** Click a mark; click it again to clear it. */
function mark(key: string, value: MeetingAttendance) {
  marks.value[key] = marks.value[key] === value ? null : value
  attendanceSaved.value = false
}

function markAllPresent() {
  for (const p of people.value) marks.value[p.key] = 'present'
  attendanceSaved.value = false
}

async function saveAttendance() {
  if (!meeting.value) return
  savingAttendance.value = true
  actionError.value = null
  const changed = people.value.filter((p) => (marks.value[p.key] ?? null) !== p.saved)
  try {
    meeting.value = await meetingsService.saveAttendance(meeting.value.id, {
      participants: changed.filter((p) => !p.guest).map((p) => ({ staff_id: Number(p.key.slice(2)), attendance: marks.value[p.key] ?? null })),
      guests: changed.filter((p) => p.guest).map((p) => ({ name: p.name, attendance: marks.value[p.key] ?? null })),
    })
    resetMarks()
    attendanceSaved.value = true
    emit('changed')
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('frontOffice.meetings.saveFailed')
  } finally {
    savingAttendance.value = false
  }
}

const markClass: Record<MeetingAttendance, string> = {
  present: 'border-success-500 bg-success-50 text-success-700',
  late: 'border-warning-500 bg-warning-50 text-warning-700',
  absent: 'border-danger-500 bg-danger-50 text-danger-700',
  excused: 'border-primary-500 bg-primary-50 text-primary-700',
}
const markVariant: Record<MeetingAttendance, 'success' | 'warning' | 'danger' | 'primary'> = { present: 'success', late: 'warning', absent: 'danger', excused: 'primary' }

async function load() {
  if (!props.meetingId) return
  loading.value = true
  loadError.value = null
  try {
    meeting.value = await meetingsService.get(props.meetingId)
    minutes.value = meeting.value.minutes ?? ''
    resetMarks()
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
    attendanceSaved.value = false
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
            <template v-if="meeting.status === 'cancelled' || !people.length">
              <span v-for="p in meeting.participants" :key="p.id" class="rounded-full bg-primary-50 px-2.5 py-0.5 text-xs font-medium text-primary-800">{{ p.name }}</span>
              <span v-for="g in guests" :key="g" class="rounded-full border border-neutral-300 px-2.5 py-0.5 text-xs text-neutral-700">
                {{ g }} · {{ t('frontOffice.meetings.guest') }}
              </span>
            </template>
            <span v-else class="text-xs text-neutral-500">{{ t('frontOffice.meetings.attendanceCounts', { n: attendanceCounts.present, ...attendanceCounts }) }}</span>
          </dd>
        </div>
      </dl>

      <!-- Attendance: who came, staff participants then guests. -->
      <div v-if="meeting.status !== 'cancelled' && people.length">
        <div class="mb-1.5 flex flex-wrap items-center justify-between gap-2">
          <h3 class="text-sm font-semibold text-neutral-900">{{ t('frontOffice.meetings.attendance') }}</h3>
          <BaseButton v-if="canMarkAttendance" size="sm" variant="outline" @click="markAllPresent">{{ t('frontOffice.meetings.allPresent') }}</BaseButton>
        </div>
        <ul class="divide-y divide-neutral-100 rounded-lg border border-neutral-200">
          <li v-for="p in people" :key="p.key" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
            <span class="text-sm text-neutral-900">
              {{ p.name }}
              <span v-if="p.guest" class="text-xs text-neutral-500">· {{ t('frontOffice.meetings.guest') }}</span>
            </span>
            <div v-if="canMarkAttendance" class="flex flex-wrap gap-1">
              <button
                v-for="a in MEETING_ATTENDANCE"
                :key="a"
                type="button"
                class="rounded-md border px-2 py-1 text-xs font-medium transition-colors"
                :class="marks[p.key] === a ? markClass[a] : 'border-neutral-300 bg-white text-neutral-600 hover:bg-neutral-50'"
                :aria-pressed="marks[p.key] === a"
                @click="mark(p.key, a)"
              >
                {{ t(`frontOffice.meetings.attendanceStates.${a}`) }}
              </button>
            </div>
            <BaseBadge v-else-if="p.saved" :variant="markVariant[p.saved]">{{ t(`frontOffice.meetings.attendanceStates.${p.saved}`) }}</BaseBadge>
            <span v-else class="text-xs text-neutral-400">{{ t('frontOffice.meetings.notMarked') }}</span>
          </li>
        </ul>
        <div v-if="canMarkAttendance" class="mt-2 flex items-center justify-end gap-3">
          <span v-if="attendanceSaved && !attendanceChanged" class="text-xs text-success-600">{{ t('frontOffice.meetings.attendanceSaved') }}</span>
          <BaseButton size="sm" :loading="savingAttendance" :disabled="!attendanceChanged" @click="saveAttendance">{{ t('frontOffice.meetings.saveAttendance') }}</BaseButton>
        </div>
      </div>

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
