<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import ScheduleChangeModal from '@/components/admin/ScheduleChangeModal.vue'
import TimetableGrid from '@/components/admin/TimetableGrid.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { timetableService, type TimetableConflict, type TimetableOptions, type TimetableSlot } from '@/services/timetable'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

/**
 * Timetable > Class: a class's week. Each time can have its subject, its own
 * teacher (else the class's teachers) and its own room (else the class's
 * room). The same times as the class form.
 */
const { t } = useI18n()
const route = useRoute()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => auth.can('timetable.manage'))
const canRequest = computed(() => auth.can('timetable.request'))

const options = ref<TimetableOptions>({ classes: [], teachers: [], rooms: [], subjects: [], me: null })
const classId = ref('')
const slots = ref<TimetableSlot[]>([])
const conflicts = ref<TimetableConflict[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const notice = ref<string | null>(null)

const classOptions = computed(() => options.value.classes.map((c) => ({ value: String(c.id), label: c.academic_year ? `${c.name} · ${c.academic_year}` : c.name })))
const current = computed(() => options.value.classes.find((c) => String(c.id) === classId.value) ?? null)
const conflictIds = computed(() => conflicts.value.flatMap((c) => c.slots.map((s) => s.id)))
const hours = computed(() => Math.round(slots.value.reduce((sum, s) => sum + (toMin(s.end_time) - toMin(s.start_time)), 0) / 6) / 10)
function toMin(time: string) {
  const [h, m] = time.split(':').map(Number)
  return (h ?? 0) * 60 + (m ?? 0)
}

async function load() {
  if (!classId.value) return
  loading.value = true
  error.value = null
  try {
    const data = await timetableService.forClass(Number(classId.value))
    slots.value = data.slots
    conflicts.value = data.conflicts
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('timetable.loadFailed')
  } finally {
    loading.value = false
  }
}
function onClass(value: string) {
  classId.value = value
  notice.value = null
  void load()
}

const conflictText = (c: TimetableConflict) =>
  t(`timetable.conflict.${c.type}`, { who: c.who ?? '', day: t(`timetable.days.d${c.day_of_week}`), slots: c.slots.map((s) => `${s.class} ${s.start_time}–${s.end_time}`).join(' / ') })

// --- Edit a time (manager) ------------------------------------------------------------
const formOpen = ref(false)
const editing = ref<TimetableSlot | null>(null)
const form = reactive({ day: '1', start: '', end: '', book: '', staff: '', room: '', note: '' })
const errors = ref<Record<string, string[]>>({})
const saving = ref(false)
const dayOptions = computed(() => [1, 2, 3, 4, 5, 6, 7].map((d) => ({ value: String(d), label: t(`timetable.days.d${d}`) })))
const subjectOptions = computed(() => [{ value: '', label: '—' }, ...options.value.subjects.map((s) => ({ value: String(s.id), label: s.title }))])
const teacherOptions = computed(() => [{ value: '', label: t('timetable.classTeachers') }, ...options.value.teachers.map((x) => ({ value: String(x.id), label: x.name }))])
const roomOptions = computed(() => [
  { value: '', label: current.value?.room ? t('timetable.classRoomIs', { room: current.value.room }) : t('timetable.classRoom') },
  ...options.value.rooms.map((r) => ({ value: String(r.id), label: r.name })),
])

function open(slot: TimetableSlot | null) {
  editing.value = slot
  form.day = String(slot?.day_of_week ?? 1)
  form.start = slot?.start_time ?? ''
  form.end = slot?.end_time ?? ''
  form.book = slot?.book_id ? String(slot.book_id) : ''
  form.staff = slot?.staff_id ? String(slot.staff_id) : ''
  form.room = slot?.classroom_id ? String(slot.classroom_id) : ''
  form.note = slot?.note ?? ''
  errors.value = {}
  formOpen.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  const input = {
    class_id: Number(classId.value),
    day_of_week: Number(form.day),
    start_time: form.start,
    end_time: form.end,
    book_id: form.book ? Number(form.book) : null,
    staff_id: form.staff ? Number(form.staff) : null,
    classroom_id: form.room ? Number(form.room) : null,
    note: form.note.trim() || null,
  }
  try {
    const r = editing.value ? await timetableService.updateSlot(editing.value.id, input) : await timetableService.createSlot(input)
    notice.value = r.conflicts.length ? t('timetable.savedWithConflicts', { count: r.conflicts.length }) : null
    formOpen.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else errors.value = { start_time: [e instanceof ApiRequestError ? e.message : t('timetable.saveFailed')] }
  } finally {
    saving.value = false
  }
}

async function remove() {
  if (!editing.value) return
  if (!(await confirmDialog.confirm({ message: t('timetable.deleteConfirm'), danger: true }))) return
  await timetableService.removeSlot(editing.value.id).catch(() => undefined)
  formOpen.value = false
  await load()
}

// --- Ask for a change (teacher) ------------------------------------------------------
const askOpen = ref(false)
const asking = ref<TimetableSlot | null>(null)
function onSlot(slot: TimetableSlot) {
  if (canManage.value) return open(slot)
  asking.value = slot
  askOpen.value = true
}

const printPage = () => window.print()

onMounted(async () => {
  try {
    options.value = await timetableService.options()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('timetable.loadFailed')
  }
  if (options.value.classes.length) {
    // From a link (e.g. Conflicts): ?class_id=…
    const asked = String(route.query.class_id ?? '')
    classId.value = options.value.classes.some((c) => String(c.id) === asked) ? asked : String(options.value.classes[0]!.id)
    await load()
  }
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2 print:hidden">
      <BaseSelect class="w-72 max-w-full" :model-value="classId" :options="classOptions" :placeholder="t('timetable.chooseClass')" @update:model-value="onClass" />
      <BaseSpinner v-if="loading" class="h-4 w-4" />
      <div class="ml-auto flex flex-wrap gap-2">
        <BaseButton v-if="classId" variant="outline" @click="printPage">{{ t('timetable.print') }}</BaseButton>
        <BaseButton v-if="canManage && classId" @click="open(null)">{{ t('timetable.addTime') }}</BaseButton>
      </div>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4 print:hidden">{{ error }}</BaseAlert>
    <BaseAlert v-if="notice" variant="warning" class="mb-4 print:hidden">{{ notice }}</BaseAlert>
    <BaseAlert v-if="conflicts.length" variant="warning" class="mb-4 print:hidden">
      <p class="font-medium">{{ t('timetable.conflictsHere', { count: conflicts.length }) }}</p>
      <p v-for="(c, i) in conflicts" :key="i" class="text-sm">{{ conflictText(c) }}</p>
    </BaseAlert>
    <p v-if="classId && (canManage || canRequest)" class="mb-3 text-xs text-neutral-500 print:hidden">{{ canManage ? t('timetable.clickToEdit') : t('timetable.clickToAsk') }}</p>

    <div v-if="classId" class="print-area">
      <div class="mb-3 hidden print:block">
        <p class="text-lg font-semibold">{{ t('timetable.classTitle', { class: current?.name ?? '' }) }}</p>
        <p class="text-sm">{{ [current?.academic_year, current?.room].filter(Boolean).join(' · ') }}</p>
      </div>
      <p class="mb-2 text-sm text-neutral-500 print:hidden">{{ t('timetable.hoursWeek', { hours }) }}<template v-if="current?.room"> · {{ t('timetable.classRoomIs', { room: current.room }) }}</template></p>
      <TimetableGrid :slots="slots" :show="['subject', 'teacher', 'room']" :conflict-ids="conflictIds" :clickable="canManage || canRequest" @open="onSlot" />
    </div>

    <BaseModal v-model="formOpen" :title="editing ? t('timetable.editTime') : t('timetable.addTime')">
      <form class="space-y-4" @submit.prevent="save">
        <div class="grid gap-4 sm:grid-cols-3">
          <BaseSelect v-model="form.day" :options="dayOptions" :label="t('timetable.day')" :error="errors.day_of_week?.[0]" />
          <BaseInput v-model="form.start" type="time" required :label="t('timetable.start')" :error="errors.start_time?.[0]" />
          <BaseInput v-model="form.end" type="time" required :label="t('timetable.end')" :error="errors.end_time?.[0]" />
        </div>
        <BaseSelect v-model="form.book" :options="subjectOptions" :label="t('timetable.subject')" :error="errors.book_id?.[0]" />
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseSelect v-model="form.staff" :options="teacherOptions" :label="t('timetable.teacher')" :error="errors.staff_id?.[0]" />
          <BaseSelect v-model="form.room" :options="roomOptions" :label="t('timetable.room')" :error="errors.classroom_id?.[0]" />
        </div>
        <BaseInput v-model="form.note" :label="t('timetable.note')" />
        <p class="text-xs text-neutral-500">{{ t('timetable.formHint') }}</p>
      </form>
      <template #footer>
        <BaseButton v-if="editing" variant="danger" class="mr-auto" @click="remove">{{ t('timetable.delete') }}</BaseButton>
        <BaseButton variant="outline" @click="formOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" :disabled="!form.start || !form.end" @click="save">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>

    <ScheduleChangeModal v-model="askOpen" :slot="asking" :options="options" @saved="notice = t('timetable.change.sent')" />
  </div>
</template>
